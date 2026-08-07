<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemReader;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Project;
use WBoost\Web\Exceptions\FileUploadNotFound;
use WBoost\Web\Mcp\Design\Dsl\BackgroundElement;
use WBoost\Web\Mcp\Design\Dsl\DesignDocument;
use WBoost\Web\Mcp\Design\Dsl\ImageElement;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Services\UploaderHelper;

/**
 * Assembles the {@see CompilationContext} for one project + one document —
 * the ONLY part of the compile that touches the database or storage.
 *
 * Splitting it out is what keeps {@see DesignCompiler} a pure function, and
 * with it every §4 invariant assertable by a plain unit test. It also keeps the
 * reads bounded: only the assets the document actually names are resolved, so
 * compiling a two-image design does not enumerate a thousand-picture library.
 *
 * ## Resolution failures are ABSENCES, not exceptions
 *
 * An id that is not a UUID, names no row, belongs to another project, is not a
 * gallery image, or sits in the trash all produce the same thing: no entry in
 * {@see CompilationContext::$assets}. The compiler turns that absence into a
 * `CompileViolation` carrying the document path (`elements[3].asset`) — one
 * error site, one wording, and the agent is told WHICH element is wrong rather
 * than merely that something is. Refusing here would produce a message with no
 * path in it and would abandon the "report every problem at once" property.
 */
readonly final class CompilationContextFactory
{
    /**
     * How much of each picture is read to recover its natural size — the same
     * bound `list_gallery` uses, for the same reason: every raster format
     * wboost stores declares its dimensions in a header near the start, and a
     * JPEG's SOF marker can sit behind a few hundred KB of EXIF and ICC.
     */
    private const int HEADER_BYTES = 262144;

    public function __construct(
        private GetFonts $getFonts,
        private FileUploadRepository $fileUploadRepository,
        private UploaderHelper $uploaderHelper,
        #[Autowire(service: 'oneup_flysystem.minio_filesystem')]
        private FilesystemReader $filesystem,
    ) {
    }

    public function forProject(Project $project, DesignDocument $document): CompilationContext
    {
        return new CompilationContext(
            allowedFonts: $this->allowedFonts($project),
            assets: $this->assetsById($project, self::referencedAssetIds($document)),
        );
    }

    /**
     * Every face string the project offers, built through
     * {@see \WBoost\Web\Entity\Font::faceFamily()} — the same single source
     * `get_context` reports to the agent and the render template registers its
     * `@font-face` under. A second `sprintf('%s (%s)')` anywhere would be a
     * family string waiting to drift by a space.
     *
     * @return list<string>
     */
    private function allowedFonts(Project $project): array
    {
        /** @var list<string> $fonts */
        $fonts = [];

        foreach ($this->getFonts->allForProject($project->id) as $font) {
            foreach ($font->faces as $face) {
                $fonts[] = $font->faceFamily($face);
            }
        }

        return $fonts;
    }

    /**
     * Gallery ids → resolved pictures, keyed by id; ids that resolve to nothing
     * are simply absent (see the class note).
     *
     * Public because {@see DecompilationContextFactory} resolves the SAME ids
     * through it. That is the property the round trip rests on: the decompiler
     * calls a picture "unnameable" exactly when the compiler would refuse the
     * id, because both ask this one method. A second resolver — with its own
     * idea of project scoping, of the trash, of a readable header — would
     * eventually report a picture the other cannot use, and `get_design` →
     * `set_design` would blank an image while telling the agent nothing.
     *
     * @param list<string> $assetIds
     * @return array<string, DesignAsset>
     */
    public function assetsById(Project $project, array $assetIds): array
    {
        $assets = [];

        foreach ($assetIds as $assetId) {
            $file = $this->file($project, $assetId);

            if ($file === null) {
                continue;
            }

            $size = $this->naturalSize($file->path);

            $assets[$assetId] = new DesignAsset(
                id: $assetId,
                path: $file->path,
                url: $this->uploaderHelper->getPublicPath($file->path),
                width: $size['width'],
                height: $size['height'],
            );
        }

        return $assets;
    }

    /**
     * The gallery row this id names, or null — see the class note on why every
     * failure mode collapses to null.
     *
     * Trashed images are excluded explicitly: a soft-deleted row still exists
     * and still has a storage object, so nothing else here would notice, and
     * `ResolveImageOverrides` would refuse the same id at fill time anyway.
     * Better to say so while the design is being compiled.
     */
    private function file(Project $project, string $assetId): null|FileUpload
    {
        if (!Uuid::isValid($assetId)) {
            return null;
        }

        try {
            $file = $this->fileUploadRepository->get(Uuid::fromString($assetId));
        } catch (FileUploadNotFound) {
            return null;
        }

        if (!$file->project->id->equals($project->id)) {
            return null;
        }

        // Source isolation is enforced by the single FileSource case today (the
        // gallery IS the project image library) — PHPStan rejects the
        // comparison as always-false, so this is the same note `ListGalleryTool`
        // carries: when FileSource gains a case, guard
        // `$file->source === FileSource::ProjectImage` here too.
        if ($file->deletedAt !== null) {
            return null;
        }

        return $file;
    }

    /**
     * @return array{width: null|int, height: null|int}
     */
    private function naturalSize(string $path): array
    {
        $none = ['width' => null, 'height' => null];

        // SVGs are stored untouched and stay vector everywhere; they have no
        // raster size, and the reader below would only invent a false negative.
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg') {
            return $none;
        }

        try {
            $stream = $this->filesystem->readStream($path);
        } catch (FilesystemException) {
            return $none;
        }

        $header = stream_get_contents($stream, self::HEADER_BYTES);
        fclose($stream);

        if ($header === false || $header === '') {
            return $none;
        }

        // Suppressed: a prefix too short to decode is an expected outcome here,
        // answered with nulls rather than a warning in the log.
        $size = @getimagesizefromstring($header);

        if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
            return $none;
        }

        return ['width' => $size[0], 'height' => $size[1]];
    }

    /**
     * Every gallery id the document names, deduped, in document order — image
     * elements, the background element, and the `canvas.background.image`
     * shorthand.
     *
     * @return list<string>
     */
    private static function referencedAssetIds(DesignDocument $document): array
    {
        /** @var array<string, true> $ids */
        $ids = [];

        $canvasBackground = $document->canvas->backgroundImageAssetId;

        if ($canvasBackground !== null) {
            $ids[$canvasBackground] = true;
        }

        foreach ($document->elements as $element) {
            if (($element instanceof ImageElement || $element instanceof BackgroundElement) && $element->assetId !== null) {
                $ids[$element->assetId] = true;
            }
        }

        return array_keys($ids);
    }
}
