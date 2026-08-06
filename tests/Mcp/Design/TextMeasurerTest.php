<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Design;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Sensiolabs\GotenbergBundle\Exception\ClientException;
use Sensiolabs\GotenbergBundle\GotenbergScreenshotInterface;
use Sensiolabs\GotenbergBundle\Processor\InMemoryProcessor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use WBoost\Web\Mcp\Design\Measure\FontMetricsLoader;
use WBoost\Web\Mcp\Design\Measure\TextMeasurer;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * {@see TextMeasurer} against the only judge that matters: Fabric v7 running in
 * Gotenberg's Chromium, over a real font file.
 *
 * **How the ground truth was obtained.** A rendered PNG does not tell you how
 * many lines a text wrapped to, so {@see testRecordedChromiumMeasurementsStillHold()}
 * asks Chromium directly. It builds a probe page carrying the same three things
 * the production render carries — the committed Fabric v7.3.1 UMD bundle, the
 * `fabric_break_word.js` patch and the face as a base64 `FontFace` — lays every
 * fixture string out in a real `fabric.Textbox`, and reports
 * `_textLines.length` + `calcTextHeight()` back out of the browser through the
 * one text channel a screenshot call has: an uncaught console exception, which
 * `failOnConsoleExceptions()` makes Gotenberg echo in its error body. That is
 * the exact mechanism the container-overflow contract already uses
 * ({@see \WBoost\Web\Exceptions\ContainerOverflow::tryFromGotenbergError()}) —
 * no new machinery, and no reading of tea leaves out of pixels.
 *
 * Those numbers are then RECORDED in {@see self::CHROMIUM} so the default suite
 * compares against them with no Gotenberg dependency; the `gotenberg`-group test
 * re-derives them live and fails if Chromium ever disagrees with the recording
 * (a Fabric upgrade, a change to the break-word patch).
 *
 * The contract asserted is the plan's: **within ±1 line**. The estimator is a
 * pre-flight for the linter, not an authority — see the class docblock of
 * {@see TextMeasurer}.
 *
 * **About the font.** The fixture project's `Font` row is called *Rubik*, but
 * the bytes written at its face path here are the repository's own
 * `public/theme/fonts/Nunito-Regular.ttf`: no Rubik binary ships with the repo,
 * and adding one just to be measured would be a binary in git for nothing. What
 * is under test is a FACE FILE, not a name — and both sides of the comparison
 * (php-font-lib here, Chromium in the probe) are handed the identical bytes. The
 * file is written in `setUp()` and removed in `tearDown()`, so no other test
 * class ever observes it (the fixtures otherwise leave that path dangling, which
 * `ScanStorageTest` relies on).
 */
final class TextMeasurerTest extends KernelTestCase
{
    /** Where the fixture `Font` row says its regular face lives. */
    private const string FACE_PATH = 'fixtures/fonts/rubik-regular.ttf';

    /** The `"Family (Face)"` wire string for that face — `Font::faceFamily()`. */
    private const string FAMILY = 'Rubik (Rubik Regular)';

    private const float LINE_HEIGHT = 1.16;

    /**
     * The fixture strings. Each one exists to stress a specific part of the
     * wrap model; `width`/`size` are canvas px.
     *
     * @var array<string, array{text: string, width: float, size: float}>
     */
    private const array CASES = [
        // Fits comfortably — proves the estimator does not invent breaks.
        'single_line' => [
            'text' => 'Nová kolekce',
            'width' => 600.0,
            'size' => 48.0,
        ],
        // Ordinary greedy wrapping over several lines.
        'paragraph' => [
            'text' => 'Vyrábíme ručně šitou koženou galanterii z českých materiálů a každý kus si projde kontrolou.',
            'width' => 600.0,
            'size' => 48.0,
        ],
        // The same copy in a much narrower box: more break decisions, so a
        // systematic width error would show up here first.
        'paragraph_narrow' => [
            'text' => 'Vyrábíme ručně šitou koženou galanterii z českých materiálů a každý kus si projde kontrolou.',
            'width' => 260.0,
            'size' => 48.0,
        ],
        // Czech diacritics end to end — precomposed codepoints that must be
        // found in `cmap`, never charged the missing-glyph fallback.
        'diacritics' => [
            'text' => 'Příliš žluťoučký kůň úpěl ďábelské ódy, řekl šéf.',
            'width' => 420.0,
            'size' => 40.0,
        ],
        // One unbreakable word far wider than the box — the break-word patch.
        'unbreakable_word' => [
            'text' => 'nejnezkomercionalizovatelnějšího',
            'width' => 300.0,
            'size' => 48.0,
        ],
        // Explicit newlines plus wrapping inside one of the paragraphs.
        'explicit_newlines' => [
            'text' => "Podzimní kolekce\nRučně šitá kožená galanterie pro každý den\n2026",
            'width' => 420.0,
            'size' => 36.0,
        ],
    ];

    /**
     * Recorded from a real Gotenberg render — regenerate with
     * `vendor/bin/phpunit --group gotenberg --filter TextMeasurerTest`, which
     * prints any drift.
     *
     * @var array<string, array{lines: int, height: float}>
     */
    private const array CHROMIUM = [
        'single_line' => ['lines' => 1, 'height' => 54.24],
        'paragraph' => ['lines' => 4, 'height' => 243.0],
        'paragraph_narrow' => ['lines' => 9, 'height' => 557.59],
        'diacritics' => ['lines' => 3, 'height' => 150.06],
        'unbreakable_word' => ['lines' => 3, 'height' => 180.08],
        'explicit_newlines' => ['lines' => 4, 'height' => 182.25],
    ];

    /**
     * @var list<string>
     */
    private array $writtenPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        $this->writeFace(self::FACE_PATH, self::fixtureFontBytes());
    }

    protected function tearDown(): void
    {
        $filesystem = self::getContainer()->get('oneup_flysystem.minio_filesystem');

        foreach ($this->writtenPaths as $path) {
            $filesystem->delete($path);
        }

        $this->writtenPaths = [];

        parent::tearDown();
    }

    /**
     * The done-when assertion: every fixture string within ±1 line of what
     * Chromium really did.
     */
    #[DataProvider('caseNames')]
    public function testEstimateIsWithinOneLineOfTheRecordedChromiumRender(string $case): void
    {
        $fixture = self::CASES[$case];
        $recorded = self::CHROMIUM[$case]['lines'];

        $estimate = $this->measurer()->estimateLines(
            self::projectId(),
            self::FAMILY,
            $fixture['size'],
            $fixture['width'],
            $fixture['text'],
        );

        self::assertNotNull($estimate, 'The fixture face must be measurable.');
        self::assertLessThanOrEqual(
            1,
            abs($estimate - $recorded),
            sprintf('%s: estimated %d lines, Chromium wrapped to %d.', $case, $estimate, $recorded),
        );
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function caseNames(): \Generator
    {
        foreach (array_keys(self::CASES) as $case) {
            yield $case => [$case];
        }
    }

    /**
     * The last-line leading rule, pinned against Chromium's own
     * `calcTextHeight()`.
     *
     * Fed the line count Chromium reported, {@see TextMeasurer::heightOfLines()}
     * must reproduce Chromium's height EXACTLY — it is pure arithmetic on
     * `fontSize`, `_fontSizeMult` and `lineHeight`, with no font metrics
     * involved. A formula that multiplied EVERY line by `lineHeight` (the
     * shipped-and-fixed "line height ignored in export" bug, from the other
     * side) comes out one full leading too tall — 16 % on the single-line
     * fixture, ~4 % at four lines — which this catches.
     */
    #[DataProvider('caseNames')]
    public function testHeightMatchesFabricCalcTextHeightForTheSameLineCount(string $case): void
    {
        $recorded = self::CHROMIUM[$case];

        self::assertEqualsWithDelta(
            $recorded['height'],
            TextMeasurer::heightOfLines($recorded['lines'], self::CASES[$case]['size'], self::LINE_HEIGHT),
            0.01,
            $case,
        );
    }

    /**
     * A one-line box is `fontSize × _fontSizeMult` tall whatever the line
     * height — the rule stated as its own assertion, because it is the half
     * everybody gets wrong.
     */
    public function testASingleLineIgnoresLineHeightEntirely(): void
    {
        $tight = TextMeasurer::heightOfLines(1, 100.0, 1.0);
        $loose = TextMeasurer::heightOfLines(1, 100.0, 3.0);

        self::assertEqualsWithDelta(113.0, $tight, 0.001);
        self::assertSame($tight, $loose);

        // Two lines: exactly one full leading is inserted BETWEEN them.
        self::assertEqualsWithDelta(113.0 + 113.0 * 3.0, TextMeasurer::heightOfLines(2, 100.0, 3.0), 0.001);
    }

    public function testEstimateHeightIsTheLineCountRunThroughTheSameFormula(): void
    {
        $fixture = self::CASES['paragraph'];
        $measurer = $this->measurer();

        $lines = $measurer->estimateLines(self::projectId(), self::FAMILY, $fixture['size'], $fixture['width'], $fixture['text']);
        $height = $measurer->estimateHeight(self::projectId(), self::FAMILY, $fixture['size'], self::LINE_HEIGHT, $fixture['width'], $fixture['text']);

        self::assertNotNull($lines);
        self::assertNotNull($height);
        self::assertEqualsWithDelta(TextMeasurer::heightOfLines($lines, $fixture['size'], self::LINE_HEIGHT), $height, 0.001);
    }

    public function testEmptyTextIsOneLineJustLikeFabric(): void
    {
        self::assertSame(1, $this->measurer()->estimateLines(self::projectId(), self::FAMILY, 48.0, 600.0, ''));
    }

    /**
     * An unknown family is not an error here — it is "no opinion". The linter
     * reports the unknown font itself (plan §4.2 invariant 10); the measurer
     * must not also explode, or a single typo would take the whole lint down.
     */
    public function testUnknownFamilyDegradesToNull(): void
    {
        self::assertNull($this->measurer()->estimateLines(self::projectId(), 'Comic Sans MS (Regular)', 48.0, 600.0, 'x'));
        self::assertNull($this->measurer()->estimateHeight(self::projectId(), 'Comic Sans MS (Regular)', 48.0, 1.16, 600.0, 'x'));
    }

    public function testFaceFileMissingFromStorageDegradesToNull(): void
    {
        $filesystem = self::getContainer()->get('oneup_flysystem.minio_filesystem');
        $filesystem->delete(self::FACE_PATH);
        $this->writtenPaths = [];

        // Nothing has parsed the face yet in this test, so the loader's memo is
        // empty and this really does exercise the failed read.
        self::assertNull($this->measurer()->estimateLines(self::projectId(), self::FAMILY, 48.0, 600.0, 'x'));
    }

    /**
     * A face php-font-lib cannot make sense of (here: not a font at all, which
     * is also how a WOFF2 upload behaves — its container is not among the magic
     * numbers `FontLib\Font::load()` recognises).
     */
    public function testUnparseableFaceFileDegradesToNull(): void
    {
        $this->writeFace(self::FACE_PATH, str_repeat('not a font', 64));

        self::assertNull($this->measurer()->estimateLines(self::projectId(), self::FAMILY, 48.0, 600.0, 'x'));
    }

    public function testDegenerateGeometryIsNotMeasured(): void
    {
        $measurer = $this->measurer();

        self::assertNull($measurer->estimateLines(self::projectId(), self::FAMILY, 48.0, 0.0, 'x'));
        self::assertNull($measurer->estimateLines(self::projectId(), self::FAMILY, 0.0, 600.0, 'x'));
    }

    /**
     * Czech diacritics are real glyphs in the fixture face, so they are measured
     * from `hmtx` and not charged the fallback.
     */
    public function testCzechDiacriticsAreRealGlyphsInTheFixtureFace(): void
    {
        $metrics = self::getContainer()->get(FontMetricsLoader::class)->forPath(self::FACE_PATH);

        self::assertNotNull($metrics);

        foreach (['ě', 'š', 'č', 'ř', 'ž', 'ý', 'á', 'í', 'é', 'ú', 'ů', 'ď', 'ť', 'ň', 'ó'] as $character) {
            self::assertTrue(
                $metrics->hasGlyphFor(mb_ord($character, 'UTF-8')),
                sprintf('"%s" must be a real glyph.', $character),
            );
        }
    }

    /**
     * A codepoint the face has no glyph for must NOT measure as free — that
     * would under-count lines exactly where a designer is most likely to be
     * surprised. Chromium falls back to another face; the estimator charges
     * `.notdef`'s advance, which is at least non-zero.
     */
    public function testUnmappedCodepointsAreNotFreeOfCharge(): void
    {
        $measurer = $this->measurer();
        // Japanese: certainly absent from a Latin face.
        $lines = $measurer->estimateLines(self::projectId(), self::FAMILY, 48.0, 300.0, str_repeat('日本語', 20));

        self::assertNotNull($lines);
        self::assertGreaterThan(1, $lines, 'Unmapped codepoints measured as zero width.');
    }

    /**
     * The real thing: ask Chromium, compare with the recording above, and
     * re-check the ±1 contract against the LIVE numbers.
     *
     * Excluded from the default suite (`phpunit.xml` excludes the `gotenberg`
     * group) because it needs the container up; run it with
     * `vendor/bin/phpunit --group gotenberg`.
     */
    #[Group('gotenberg')]
    public function testRecordedChromiumMeasurementsStillHold(): void
    {
        $measured = $this->chromiumMeasurements();
        $measurer = $this->measurer();

        // One assertion over the whole map, with the regenerated literal in the
        // message — drift is then a copy-paste away from being recorded.
        self::assertEquals(self::CHROMIUM, $measured, "self::CHROMIUM is stale. Recorded values from this run:\n" . self::asPhpLiteral($measured));

        foreach (self::CASES as $case => $fixture) {
            self::assertArrayHasKey($case, $measured, 'The probe did not report ' . $case . '.');

            $estimate = $measurer->estimateLines(
                self::projectId(),
                self::FAMILY,
                $fixture['size'],
                $fixture['width'],
                $fixture['text'],
            );

            self::assertNotNull($estimate);
            self::assertLessThanOrEqual(
                1,
                abs($estimate - $measured[$case]['lines']),
                sprintf('%s: estimated %d lines, Chromium wrapped to %d.', $case, $estimate, $measured[$case]['lines']),
            );
        }
    }

    /**
     * Lay every fixture out in a real Fabric Textbox inside Gotenberg's
     * Chromium and read the result back.
     *
     * @return array<string, array{lines: int, height: float}>
     */
    private function chromiumMeasurements(): array
    {
        // Typed as the INTERFACE on purpose: the concrete `GotenbergScreenshot`
        // declares `html()` as a union with the debug TraceableBuilder, whose
        // fluent methods only exist via `__call`.
        /** @var GotenbergScreenshotInterface $gotenberg */
        $gotenberg = self::getContainer()->get(GotenbergScreenshotInterface::class);

        try {
            $gotenberg->html()
                ->contentRaw($this->probeHtml())
                ->width(32)
                ->height(32)
                ->clip(true)
                ->failOnConsoleExceptions()
                ->waitForExpression('window.probeDone === true')
                ->generate()
                ->processor(new InMemoryProcessor())
                ->process();
        } catch (ClientException $exception) {
            return self::parseProbePayload(self::gotenbergErrorBody($exception));
        }

        self::fail('The probe page did not signal its measurements (no console exception reached Gotenberg).');
    }

    /**
     * The probe page: the production render's font + Fabric + break-word setup,
     * a Textbox per fixture, and the measurements thrown out as an uncaught
     * error. `setTimeout` is what makes it uncaught (it escapes the enclosing
     * try/catch and the async function body) — the same trick the render
     * template uses for `CONTAINER_OVERFLOW`.
     */
    private function probeHtml(): string
    {
        $cases = [];

        foreach (self::CASES as $case => $fixture) {
            $cases[] = [
                'key' => $case,
                'text' => $fixture['text'],
                'width' => $fixture['width'],
                'size' => $fixture['size'],
            ];
        }

        return sprintf(
            <<<'HTML'
                <!doctype html>
                <html lang="en"><head><meta charset="utf-8"><title>probe</title></head>
                <body>
                <canvas id="c" width="32" height="32"></canvas>
                <script>%s</script>
                <script>%s</script>
                <script>
                (async () => {
                    try {
                        const face = new FontFace('ProbeFace', 'url("data:font/ttf;base64,%s")');
                        await face.load();
                        document.fonts.add(face);
                        await document.fonts.ready;

                        WBoostFabricBreakWord.enable(fabric.Textbox);
                        new fabric.StaticCanvas('c', { width: 32, height: 32, enableRetinaScaling: false });

                        const out = {};
                        for (const c of %s) {
                            const box = new fabric.Textbox(c.text, {
                                left: 0,
                                top: 0,
                                width: c.width,
                                fontFamily: 'ProbeFace',
                                fontSize: c.size,
                                lineHeight: %s,
                                charSpacing: 0,
                                splitByGrapheme: false,
                            });
                            out[c.key] = {
                                lines: box._textLines.length,
                                height: Math.round(box.calcTextHeight() * 100) / 100,
                            };
                        }
                        setTimeout(() => { throw new Error('PROBE:' + btoa(JSON.stringify(out))); }, 0);
                        await new Promise((resolve) => setTimeout(resolve, 200));
                    } catch (err) {
                        setTimeout(() => { throw new Error('PROBE_FAILED:' + String((err && err.stack) || err)); }, 0);
                        await new Promise((resolve) => setTimeout(resolve, 200));
                    }
                    window.probeDone = true;
                })();
                </script>
                </body></html>
                HTML,
            self::readProjectFile('assets/fabric/fabric-7.3.1.min.js'),
            self::readProjectFile('assets/editor/fabric_break_word.js'),
            base64_encode(self::fixtureFontBytes()),
            json_encode($cases, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            self::LINE_HEIGHT,
        );
    }

    /**
     * @return array<string, array{lines: int, height: float}>
     */
    private static function parseProbePayload(string $body): array
    {
        self::assertStringNotContainsString('PROBE_FAILED:', $body, 'The probe page itself failed: ' . $body);
        self::assertSame(1, preg_match('/PROBE:([A-Za-z0-9+\/=]+)/', $body, $matches), 'No probe marker in: ' . $body);

        $json = base64_decode($matches[1], true);
        self::assertIsString($json);

        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        $measured = [];

        foreach ($payload as $case => $result) {
            self::assertIsString($case);
            self::assertIsArray($result);

            $lines = $result['lines'] ?? null;
            $height = $result['height'] ?? null;
            self::assertIsInt($lines);
            self::assertTrue(is_int($height) || is_float($height));

            $measured[$case] = ['lines' => $lines, 'height' => (float) $height];
        }

        return $measured;
    }

    /**
     * @param array<string, array{lines: int, height: float}> $measured
     */
    private static function asPhpLiteral(array $measured): string
    {
        $lines = [];

        foreach ($measured as $case => $result) {
            $lines[] = sprintf("        '%s' => ['lines' => %d, 'height' => %s],", $case, $result['lines'], var_export($result['height'], true));
        }

        return implode("\n", $lines);
    }

    /**
     * The Gotenberg error body carrying the page's uncaught exception — only
     * reachable through the wrapped HttpClient exception's response.
     */
    private static function gotenbergErrorBody(ClientException $exception): string
    {
        $previous = $exception->getPrevious();

        if ($previous instanceof HttpExceptionInterface) {
            return $previous->getResponse()->getContent(false);
        }

        return $exception->getMessage();
    }

    private function measurer(): TextMeasurer
    {
        return self::getContainer()->get(TextMeasurer::class);
    }

    private function writeFace(string $path, string $bytes): void
    {
        self::getContainer()->get('oneup_flysystem.minio_filesystem')->write($path, $bytes);

        if (!in_array($path, $this->writtenPaths, true)) {
            $this->writtenPaths[] = $path;
        }
    }

    private static function fixtureFontBytes(): string
    {
        return self::readProjectFile('public/theme/fonts/Nunito-Regular.ttf');
    }

    private static function readProjectFile(string $relativePath): string
    {
        $contents = file_get_contents(self::getContainer()->getParameter('kernel.project_dir') . '/' . $relativePath);
        self::assertIsString($contents);

        return $contents;
    }

    private static function projectId(): UuidInterface
    {
        return Uuid::fromString(TestDataFixture::PROJECT_1_ID);
    }
}
