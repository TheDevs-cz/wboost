<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Measure;

use FontLib\Font as FontParser;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemReader;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use WBoost\Web\Query\GetFonts;

/**
 * Resolves a project font face — addressed the way the whole app addresses one,
 * by the `"Family (Face)"` wire string of {@see \WBoost\Web\Entity\Font::faceFamily()}
 * — to its parsed {@see FontMetrics}, reading the face file out of the object
 * store and parsing it with `php-font-lib` (the same library
 * {@see \WBoost\Web\MessageHandler\Font\AddFontHandler} already parses uploads with).
 *
 * **Everything is memoized, including failure.** Two maps, mirroring
 * {@see \WBoost\Web\Services\Editor\TemplateVariantImageRenderer::$inlinedFonts}:
 * project => (family => face path), and face path => metrics-or-null. A face
 * file is immutable per path (uploads are timestamped), so a hit is always
 * valid, and caching the null means a missing face costs ONE failed object-store
 * read per request rather than one per measured string. Under FrankenPHP's
 * resident worker both maps also survive across requests.
 *
 * **Nothing here throws.** Every failure mode — face not in the project, file
 * gone from storage, a container `php-font-lib` cannot open (WOFF2 is the real
 * one: it is not among the four magic numbers `FontLib\Font::load()` recognises),
 * a corrupt table — collapses to `null`, because the caller is a pre-flight
 * estimator feeding a linter. A design must not fail to lint because a font file
 * is unreadable; the unreadable font is its own, separately reported problem.
 */
final class FontMetricsLoader
{
    /** @var array<string, array<string, string>> project id => family => face file path */
    private array $facePaths = [];

    /** @var array<string, null|FontMetrics> face file path => parsed metrics (null = unusable) */
    private array $metrics = [];

    public function __construct(
        #[Autowire(service: 'oneup_flysystem.minio_filesystem')]
        private readonly FilesystemReader $filesystem,
        private readonly GetFonts $getFonts,
    ) {
    }

    /**
     * Metrics for `$fontFamily` within `$projectId`, or null when the project
     * has no such face or the face file cannot be read/parsed.
     */
    public function forFamily(UuidInterface $projectId, string $fontFamily): null|FontMetrics
    {
        $path = $this->facePathsOf($projectId)[$fontFamily] ?? null;

        if ($path === null) {
            return null;
        }

        return $this->forPath($path);
    }

    /**
     * Metrics for an object-store face path, or null when it cannot be read or
     * parsed.
     */
    public function forPath(string $path): null|FontMetrics
    {
        if (!array_key_exists($path, $this->metrics)) {
            $this->metrics[$path] = $this->parse($path);
        }

        return $this->metrics[$path];
    }

    /**
     * @return array<string, string> family wire string => face file path
     */
    private function facePathsOf(UuidInterface $projectId): array
    {
        $key = $projectId->toString();

        if (!array_key_exists($key, $this->facePaths)) {
            $paths = [];

            foreach ($this->getFonts->allForProject($projectId) as $font) {
                foreach ($font->faces as $face) {
                    $paths[$font->faceFamily($face)] = $face->filePath;
                }
            }

            $this->facePaths[$key] = $paths;
        }

        return $this->facePaths[$key];
    }

    private function parse(string $path): null|FontMetrics
    {
        try {
            $contents = $this->filesystem->read($path);
        } catch (FilesystemException) {
            return null;
        }

        // php-font-lib only opens a real file (it sniffs the magic number with
        // file_get_contents and then streams the tables), so the object-store
        // bytes have to land on disk for the length of the parse.
        $file = tempnam(sys_get_temp_dir(), 'wboost-font-');

        if ($file === false) {
            return null;
        }

        try {
            if (file_put_contents($file, $contents) === false) {
                return null;
            }

            return $this->parseFile($file);
        } catch (\Throwable) {
            // Deliberately broad: a truncated table, an unexpected magic
            // number, an out-of-range seek — php-font-lib signals all of them
            // with plain exceptions, and none of them may take a lint down.
            return null;
        } finally {
            @unlink($file);
        }
    }

    private function parseFile(string $file): null|FontMetrics
    {
        $font = FontParser::load($file);

        if ($font === null) {
            // Not one of the containers php-font-lib recognises — WOFF2 in
            // practice, which the app accepts as an upload and Chromium renders
            // happily. Such a face is simply not measurable here.
            return null;
        }

        $font->parse();

        $unitsPerEm = $font->getData('head', 'unitsPerEm');
        $charMap = $font->getUnicodeCharMap();
        $horizontalMetrics = $font->getData('hmtx');

        if (!is_int($unitsPerEm) || $unitsPerEm <= 0 || !is_array($charMap) || !is_array($horizontalMetrics)) {
            return null;
        }

        $advanceWidths = [];

        foreach ($charMap as $codepoint => $glyphId) {
            if (!is_int($codepoint) || !is_int($glyphId)) {
                continue;
            }

            $advance = $this->advanceOfGlyph($horizontalMetrics, $glyphId);

            if ($advance === null) {
                continue;
            }

            $advanceWidths[$codepoint] = $advance;
        }

        if ($advanceWidths === []) {
            return null;
        }

        $postScriptName = $font->getFontPostscriptName();

        return new FontMetrics(
            is_string($postScriptName) ? $postScriptName : null,
            $unitsPerEm,
            $advanceWidths,
            $this->resolveFallbackAdvance($horizontalMetrics, $advanceWidths, $unitsPerEm),
            FontCalibration::factorFor(is_string($postScriptName) ? $postScriptName : null),
        );
    }

    /**
     * The advance charged to a codepoint the face has no glyph for.
     *
     * Chromium does not draw `.notdef` in that case — it falls back to another
     * installed face, whose glyph has a perfectly ordinary width. So the one
     * answer that is certainly WRONG is zero, which would make an unsupported
     * script measure as free and under-count lines. Preference order:
     * `.notdef`'s own advance (the face's idea of "one unknown character"),
     * then the mean of the face's positive advances, then half an em.
     *
     * @param array<mixed> $horizontalMetrics
     * @param array<int, int> $advanceWidths
     */
    private function resolveFallbackAdvance(array $horizontalMetrics, array $advanceWidths, int $unitsPerEm): int
    {
        $notdef = $this->advanceOfGlyph($horizontalMetrics, 0);

        if ($notdef !== null && $notdef > 0) {
            return $notdef;
        }

        $positive = array_filter($advanceWidths, static fn (int $advance): bool => $advance > 0);

        if ($positive !== []) {
            return (int) round(array_sum($positive) / count($positive));
        }

        return (int) round($unitsPerEm / 2);
    }

    /**
     * `hmtx` rows are `[advanceWidth, leftSideBearing]`; the table is `mixed`
     * out of php-font-lib, so it is narrowed here rather than trusted.
     *
     * @param array<mixed> $horizontalMetrics
     */
    private function advanceOfGlyph(array $horizontalMetrics, int $glyphId): null|int
    {
        $row = $horizontalMetrics[$glyphId] ?? null;

        if (!is_array($row)) {
            return null;
        }

        $advance = $row[0] ?? null;

        return is_int($advance) ? $advance : null;
    }
}
