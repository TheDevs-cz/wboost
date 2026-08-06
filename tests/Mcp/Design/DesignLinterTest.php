<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Design;

use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Exceptions\DesignCompilationFailed;
use WBoost\Web\Mcp\Design\CompilationContext;
use WBoost\Web\Mcp\Design\DesignCompiler;
use WBoost\Web\Mcp\Design\DesignIdentity;
use WBoost\Web\Mcp\Design\Dsl\CanvasSpec;
use WBoost\Web\Mcp\Design\Dsl\ContainerElement;
use WBoost\Web\Mcp\Design\Dsl\DesignDocument;
use WBoost\Web\Mcp\Design\Dsl\DesignElement;
use WBoost\Web\Mcp\Design\Dsl\ImageElement;
use WBoost\Web\Mcp\Design\Dsl\ImageInputSpec;
use WBoost\Web\Mcp\Design\Dsl\Placement;
use WBoost\Web\Mcp\Design\Dsl\TextAlign;
use WBoost\Web\Mcp\Design\Dsl\TextElement;
use WBoost\Web\Mcp\Design\Dsl\TextInputSpec;
use WBoost\Web\Mcp\Design\Lint\DesignLinter;
use WBoost\Web\Mcp\Design\Lint\LintCode;
use WBoost\Web\Mcp\Design\Lint\LintContext;
use WBoost\Web\Mcp\Design\Lint\LintFinding;
use WBoost\Web\Mcp\Design\Lint\LintReport;
use WBoost\Web\Query\GetManuals;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * {@see DesignLinter} — the deterministic half of plan §0.5's *"lint + text
 * measurement BEFORE spending a render"*.
 *
 * ## The guard that keeps the linter honest
 *
 * {@see testEveryCodeHasAFixtureThatTriggersItExactlyOnce()} iterates
 * {@see LintCode::cases()} and looks each one up in a `match` with no `default`.
 * A code added without a fixture therefore does not merely go untested — the
 * data-provider row throws `UnhandledMatchError` and the suite fails. Each
 * fixture is also asserted to produce that finding and **nothing else**, which
 * is the other half of the same guarantee: a check that fires on the
 * neighbouring fixture is a check that will fire on real designs.
 *
 * ## Why a kernel test
 *
 * The overflow and bounds predictions run on real {@see \WBoost\Web\Mcp\Design\Measure\TextMeasurer}
 * output, which needs a real face file. Stubbing it would test the linter
 * against a fiction — and the one property that matters most here
 * (*"`null` degrades to no opinion"*) is only meaningful against the real
 * loader's actual failure mode. The face is written to the fixture project's
 * font path in `setUp()` and removed in `tearDown()`, exactly as
 * {@see TextMeasurerTest} does it (and the bytes are the same repository-owned
 * Nunito file, for the same reason: no font binary is added to git just to be
 * measured).
 */
final class DesignLinterTest extends KernelTestCase
{
    /** Where the fixture `Font` row says its regular face lives. */
    private const string FACE_PATH = 'fixtures/fonts/rubik-regular.ttf';

    /** A face string that is BOTH allowed and measurable. */
    private const string FAMILY = 'Rubik (Rubik Regular)';

    /**
     * A face string that is allowed but NOT measurable — no `Font` row backs
     * it, so `TextMeasurer` answers null for it. This is how the "no opinion"
     * path is exercised without also raising `font_not_allowed`.
     */
    private const string UNMEASURABLE_FAMILY = 'Ghost (Ghost Regular)';

    /** A face string the project does not have at all. */
    private const string FOREIGN_FAMILY = 'Comic Sans MS (Comic Sans MS Regular)';

    /** {@see TestDataFixture}'s manual 1 primary / secondary colours. */
    private const string BRAND_PRIMARY = '#c8102e';
    private const string BRAND_SECONDARY = '#004e7c';

    private const int CANVAS_SIDE = 1080;

    /** @var list<string> */
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

    // -----------------------------------------------------------------
    // the done-when guard
    // -----------------------------------------------------------------

    /**
     * Every code: a fixture that triggers it exactly once, and nothing else.
     */
    #[DataProvider('codes')]
    public function testEveryCodeHasAFixtureThatTriggersItExactlyOnce(LintCode $code): void
    {
        $report = $this->lint(self::fixtureFor($code));

        self::assertSame(
            [$code->value],
            array_map(static fn (LintFinding $finding): string => $finding->code->value, $report->findings),
            sprintf('The %s fixture must trigger that code exactly once and nothing else.', $code->value),
        );

        $finding = $report->findings[0];

        self::assertSame($code->severity(), $finding->severity());
        self::assertNotSame('', $finding->slug, 'Every finding names the offending element by slug.');
        self::assertStringContainsString(
            $finding->path,
            $finding->message,
            'A message must contain its own path so it reads standalone in a joined list.',
        );
        self::assertStringEndsWith('.', $finding->message);
    }

    /**
     * @return iterable<string, array{LintCode}>
     */
    public static function codes(): iterable
    {
        foreach (LintCode::cases() as $case) {
            yield $case->value => [$case];
        }
    }

    /**
     * The fixture per code. `match` with **no default**: a code without a
     * fixture is an `UnhandledMatchError`, not a silent gap.
     */
    private static function fixtureFor(LintCode $code): DesignDocument
    {
        return match ($code) {
            LintCode::FontNotAllowed => self::document([
                self::text('headline', 80.0, 80.0, 920.0, font: self::FOREIGN_FAMILY),
            ]),
            LintCode::OutOfCanvasBounds => self::document([
                self::text('headline', 80.0, 80.0, 920.0),
                // 480 px tall starting at 720 on a 1080 canvas: 120 px cropped.
                self::image('photo', 80.0, 720.0, 920.0, 480.0, placeholder: true),
            ]),
            LintCode::TextOverlap => self::document([
                self::text('headline', 80.0, 200.0, 920.0),
                self::text('subhead', 80.0, 240.0, 920.0),
            ]),
            LintCode::ColorNotInPalette => self::document([
                self::text('headline', 80.0, 80.0, 920.0, color: '#ff00ff'),
            ]),
            LintCode::FontSizeTooSmall => self::document([
                // The floor on a 1080 canvas is 10.8 px.
                self::text('legal', 80.0, 80.0, 920.0, size: 8.0),
            ]),
            LintCode::ContainerOverflowPredicted => self::document([
                self::text('headline', 80.0, 200.0, 920.0),
                self::text('subhead', 80.0, 400.0, 920.0),
                self::container('body', ['headline', 'subhead'], maxHeight: 100.0),
            ]),
            LintCode::ContainerTooFewItems => self::document([
                self::text('headline', 80.0, 200.0, 920.0),
                // Built as a VO on purpose: DslParser refuses this, so it can
                // only reach the linter from a document nobody parsed — the
                // decompiler's output for an editor-authored canvas.
                self::container('body', ['headline']),
            ]),
            LintCode::ImageWithoutAssetOrPlaceholder => self::document([
                self::image('photo', 80.0, 400.0, 920.0, 400.0, placeholder: false),
            ]),
            LintCode::MaxLengthBelowStandIn => self::document([
                self::text('headline', 80.0, 80.0, 920.0, text: 'SLEVA 50 % NA VŠECHNO', maxLength: 10),
            ]),
        };
    }

    // -----------------------------------------------------------------
    // the other half: not firing
    // -----------------------------------------------------------------

    /**
     * A linter that always has something to say is one nobody reads.
     */
    public function testACleanDesignProducesNoFindings(): void
    {
        $report = $this->lint(self::document([
            self::text('headline', 80.0, 80.0, 920.0, color: self::BRAND_PRIMARY),
            self::text('claim', 80.0, 260.0, 920.0, text: 'Jaro 2026', size: 48.0, color: self::BRAND_SECONDARY),
            self::image('photo', 80.0, 400.0, 920.0, 500.0, placeholder: true),
        ]));

        self::assertSame([], $report->toArray());
        self::assertTrue($report->isClean());
        self::assertFalse($report->hasErrors());
    }

    /**
     * Containers exist so that texts MAY overlap by design — the flow engine
     * preserves negative designed gaps deliberately.
     */
    public function testTextsInTheSameContainerMayOverlap(): void
    {
        $report = $this->lint(self::document([
            self::text('headline', 80.0, 200.0, 920.0),
            self::text('subhead', 80.0, 240.0, 920.0),
            self::container('body', ['headline', 'subhead'], maxHeight: 600.0),
        ]));

        self::assertSame([], $report->toArray());
    }

    /**
     * The unit is the whole container TREE, not the single container: a nested
     * container flows inside its parent as one item, so an overlap between the
     * parent's text and the child's is just as intentional.
     */
    public function testTextsInTheSameContainerTreeMayOverlapAcrossNesting(): void
    {
        $report = $this->lint(self::document([
            self::text('headline', 80.0, 200.0, 920.0),
            self::text('subhead', 80.0, 240.0, 920.0),
            self::text('note', 80.0, 700.0, 920.0),
            self::container('section', ['subhead', 'note'], maxHeight: 600.0),
            self::container('body', ['headline'], ['section'], maxHeight: 900.0),
        ]));

        self::assertSame([], $report->toArray());
    }

    /**
     * Different trees is a real collision: same geometry as the test above,
     * with the two texts in two separate containers.
     */
    public function testTextsInDifferentContainersStillWarn(): void
    {
        $report = $this->lint(self::document([
            self::text('headline', 80.0, 200.0, 920.0),
            self::text('subhead', 80.0, 240.0, 920.0),
            self::text('note', 80.0, 700.0, 920.0),
            self::text('footer', 80.0, 860.0, 920.0),
            self::container('left', ['headline', 'note'], maxHeight: 900.0),
            self::container('right', ['subhead', 'footer'], maxHeight: 900.0),
        ]));

        self::assertCount(1, $report->withCode(LintCode::TextOverlap));
        self::assertSame('subhead', $report->withCode(LintCode::TextOverlap)[0]->slug);
    }

    /**
     * A fillable image placeholder is never a container member (§4.4-18 — its
     * frame is load-bearing for the API and the fill page), so it does not
     * count towards the two items a container needs. `DslParser` refuses such a
     * member outright; this is the decompiled-canvas path, where the definition
     * is real and inert.
     */
    public function testAFillablePlaceholderDoesNotCountAsAContainerMember(): void
    {
        $report = $this->lint(self::document([
            self::text('headline', 80.0, 200.0, 920.0),
            self::image('photo', 80.0, 400.0, 920.0, 400.0, placeholder: true),
            self::container('body', ['headline', 'photo']),
        ]));

        self::assertCount(1, $report->withCode(LintCode::ContainerTooFewItems));
        self::assertStringContainsString('groups 1 item(s)', $report->withCode(LintCode::ContainerTooFewItems)[0]->message);
    }

    // -----------------------------------------------------------------
    // the measurer's one-sidedness
    // -----------------------------------------------------------------

    /**
     * `null` from {@see \WBoost\Web\Mcp\Design\Measure\TextMeasurer} means "no
     * opinion", never zero: the SAME geometry that overflows with a measurable
     * face reports nothing at all with an unmeasurable one (a WOFF2 upload, a
     * face file gone from storage — both real).
     */
    public function testAnUnmeasurableFaceSilencesTheOverflowPredictionRatherThanGuessing(): void
    {
        $measurable = $this->lint(self::document([
            self::text('headline', 80.0, 200.0, 920.0),
            self::text('subhead', 80.0, 400.0, 920.0),
            self::container('body', ['headline', 'subhead'], maxHeight: 100.0),
        ]));

        self::assertCount(1, $measurable->withCode(LintCode::ContainerOverflowPredicted));

        $unmeasurable = $this->lint(self::document([
            self::text('headline', 80.0, 200.0, 920.0, font: self::UNMEASURABLE_FAMILY),
            self::text('subhead', 80.0, 400.0, 920.0, font: self::UNMEASURABLE_FAMILY),
            self::container('body', ['headline', 'subhead'], maxHeight: 100.0),
        ]));

        self::assertSame([], $unmeasurable->toArray());
    }

    /**
     * A container that overflows the PAGE rather than its own `maxHeight` —
     * content ending below `canvasHeight − spaceAfter` is overflow too, and the
     * message says which bound was hit.
     */
    public function testOverflowIsAlsoReportedAgainstThePageBottom(): void
    {
        $report = $this->lint(self::document([
            self::text('headline', 80.0, 700.0, 920.0),
            self::text('subhead', 80.0, 900.0, 920.0),
            self::container('body', ['headline', 'subhead'], maxHeight: 900.0, spaceAfter: 80.0),
        ]));

        $findings = $report->withCode(LintCode::ContainerOverflowPredicted);

        self::assertCount(1, $findings);
        self::assertStringContainsString('below the page bottom', $findings[0]->message);
        self::assertStringContainsString('spaceAfter', $findings[0]->message);
    }

    // -----------------------------------------------------------------
    // the font error, and its agreement with the compiler
    // -----------------------------------------------------------------

    /**
     * The error must be the compiler's error, word for word: the linter calls
     * the compiler's predicate and its message builder, so the agent cannot be
     * told two different things about the same font depending on which stage
     * spoke.
     */
    public function testTheFontErrorIsTheCompilersOwnViolationWordForWord(): void
    {
        $document = self::fixtureFor(LintCode::FontNotAllowed);
        $context = self::context();

        $errors = $this->lint($document)->errors();

        self::assertCount(1, $errors);

        try {
            self::getContainer()->get(DesignCompiler::class)->compile($document, $context->compilation, DesignIdentity::fresh());

            self::fail('The compiler must refuse a font the project does not have.');
        } catch (DesignCompilationFailed $exception) {
            self::assertCount(1, $exception->violations);
            self::assertSame($exception->violations[0]->message, $errors[0]->message);
            self::assertSame($exception->violations[0]->path, $errors[0]->path);
            self::assertSame($exception->violations[0]->code->value, $errors[0]->code->value);
        }
    }

    /**
     * …and it BLOCKS: `preview_design` keys on this to answer without rendering.
     */
    public function testAnUnknownFontIsTheOnlyBlockingSeverity(): void
    {
        self::assertTrue($this->lint(self::fixtureFor(LintCode::FontNotAllowed))->hasErrors());

        foreach (LintCode::cases() as $code) {
            if ($code === LintCode::FontNotAllowed) {
                continue;
            }

            self::assertFalse(
                $this->lint(self::fixtureFor($code))->hasErrors(),
                sprintf('%s must not block the render.', $code->value),
            );
        }
    }

    // -----------------------------------------------------------------
    // the thresholds, against real numbers
    // -----------------------------------------------------------------

    /**
     * The floor is `min(1 % of the canvas height, 24 px)`, and the cap is what
     * keeps it off legitimate print fine print.
     */
    public function testTheLegibilityFloorMatchesTheDocumentedFormula(): void
    {
        self::assertEqualsWithDelta(10.8, DesignLinter::legibilityFloor(1080), 0.001);
        self::assertEqualsWithDelta(19.2, DesignLinter::legibilityFloor(1920), 0.001);
        // A4 at the app's 300-DPI print raster: the cap, not 35 px.
        self::assertEqualsWithDelta(24.0, DesignLinter::legibilityFloor(3508), 0.001);
    }

    /**
     * Sanity check against the smallest type actually in use in this app's own
     * template canvases: 20 px on a px-unit canvas, 38.8 px on an mm-unit one
     * (measured across every textbox in the database). Neither may warn — a
     * linter that cries wolf gets ignored, which is worse than not existing.
     */
    public function testTheSmallestTypeRealTemplatesUseDoesNotWarn(): void
    {
        $social = $this->lint(self::document([
            self::text('caption', 80.0, 80.0, 920.0, text: 'Drobné písmo', size: 20.0),
        ]));

        self::assertSame([], $social->toArray());

        $print = $this->lint(new DesignDocument(
            new CanvasSpec(2480, 3508),
            [self::text('legal', 200.0, 3000.0, 2080.0, text: 'Drobné písmo', size: 38.8)],
        ));

        self::assertSame([], $print->toArray());
    }

    /**
     * Abutting is not overlapping: two boxes that merely touch (the tolerance
     * exists because neighbouring grid spans share a rounded edge) say nothing.
     */
    public function testBoxesThatMerelyTouchAreNotAnOverlap(): void
    {
        // One line of 96 px type is 96 × 1.13 = 108.48 px tall.
        $report = $this->lint(self::document([
            self::text('headline', 80.0, 100.0, 920.0),
            self::text('subhead', 80.0, 207.0, 920.0),
        ]));

        self::assertSame([], $report->withCode(LintCode::TextOverlap));
    }

    /**
     * Half a pixel over the edge is rounding, not a mistake.
     */
    public function testASubPixelExcursionIsNotOutOfBounds(): void
    {
        $report = $this->lint(self::document([
            self::image('photo', 80.0, 400.0, 1000.3, 400.0, placeholder: true),
        ]));

        self::assertSame([], $report->toArray());
    }

    // -----------------------------------------------------------------
    // maxLength vs the stand-in (plan risk R9)
    // -----------------------------------------------------------------

    /**
     * A rich `sampleValue` is a `{"runs":[…]}` envelope, so its length is the
     * PLAIN-TEXT projection — `strlen` on the JSON blob would report 100-odd
     * characters of markup and warn about a value that fits.
     */
    public function testARichSampleIsMeasuredByItsPlainTextProjectionNotItsJson(): void
    {
        $envelope = json_encode([
            'runs' => [
                ['text' => 'SLEVA ', 'fontFamily' => null, 'color' => '#c8102e', 'underline' => false],
                ['text' => '50 %', 'fontFamily' => null, 'color' => null, 'underline' => true],
            ],
        ], JSON_THROW_ON_ERROR);

        // 10 plain characters inside ~180 characters of envelope.
        self::assertGreaterThan(100, strlen($envelope));

        $fits = $this->lint(self::document([
            self::text('headline', 80.0, 80.0, 920.0, maxLength: 10, sampleValue: $envelope, richText: true),
        ]));

        self::assertSame([], $fits->toArray());

        $overflows = $this->lint(self::document([
            self::text('headline', 80.0, 80.0, 920.0, maxLength: 9, sampleValue: $envelope, richText: true),
        ]));

        $findings = $overflows->withCode(LintCode::MaxLengthBelowStandIn);

        self::assertCount(1, $findings);
        self::assertStringContainsString('is 10 characters', $findings[0]->message);
        self::assertStringContainsString('sampleValue', $findings[0]->message);
    }

    /**
     * The same envelope on a NON-rich input is literal text, exactly as
     * `ResolveTextOverrides::parseValue()` treats it — so its length is the
     * JSON's, and pretending otherwise would report a number nobody renders.
     */
    public function testAnEnvelopeOnAPlainInputIsLiteralText(): void
    {
        $envelope = '{"runs":[{"text":"SLEVA 50 %"}]}';

        $report = $this->lint(self::document([
            self::text('headline', 80.0, 80.0, 920.0, maxLength: 20, sampleValue: $envelope, richText: false),
        ]));

        self::assertCount(1, $report->withCode(LintCode::MaxLengthBelowStandIn));
    }

    /**
     * Length is compared BEFORE upper-casing, because the render truncates
     * first and upper-cases second — and case mapping can CHANGE the length
     * (`ß` → `SS`). Pre-upper-casing here would warn about a value that fits.
     */
    public function testUppercaseIsNotAppliedBeforeTheMaxLengthComparison(): void
    {
        // `straße12` is 8 characters, which is exactly maxLength — but
        // `mb_strtoupper()` maps `ß` to `SS` and makes it 9, so an
        // implementation that upper-cased before comparing would warn here.
        $report = $this->lint(self::document([
            self::text('headline', 80.0, 80.0, 920.0, maxLength: 8, sampleValue: 'straße12', uppercase: true),
        ]));

        self::assertSame([], $report->toArray());
    }

    // -----------------------------------------------------------------
    // context
    // -----------------------------------------------------------------

    /**
     * The palette is the app's palette — {@see ResolveRichTextOptions::computeColors()}
     * over the project's manuals, the same list `get_context` reports and the
     * fill toolbar offers.
     */
    public function testTheBrandPaletteComesFromTheProjectsManuals(): void
    {
        $manuals = self::getContainer()->get(GetManuals::class)->allForProject(self::projectId());

        $context = LintContext::forProject(self::projectId(), CompilationContext::empty(), $manuals);

        self::assertSame(ResolveRichTextOptions::computeColors($manuals), $context->brandColors);
        self::assertContains(self::BRAND_PRIMARY, $context->brandColors);
        self::assertTrue($context->isBrandColor('#C8102E'), 'Case and shorthand are normalized the app\'s way.');
    }

    /**
     * A project with no brand manual has no palette — and then the check is
     * skipped entirely rather than flagging every colour in the document.
     */
    public function testAProjectWithoutBrandColoursIsNotNaggedAboutColour(): void
    {
        $context = new LintContext(self::projectId(), new CompilationContext([self::FAMILY], []), []);

        $report = self::getContainer()->get(DesignLinter::class)->lint(
            self::document([self::text('headline', 80.0, 80.0, 920.0, color: '#ff00ff')]),
            $context,
        );

        self::assertSame([], $report->toArray());
    }

    /**
     * Black and white are never off-brand: black is the DSL's default `color`,
     * so flagging it would fire on designs where nobody authored a colour.
     */
    public function testNeutralsAreNeverOffBrand(): void
    {
        $report = $this->lint(self::document([
            self::text('ink', 80.0, 80.0, 920.0, color: '#000000'),
            self::text('paper', 80.0, 400.0, 920.0, color: '#ffffff'),
        ]));

        self::assertSame([], $report->toArray());
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private function lint(DesignDocument $document): LintReport
    {
        return self::getContainer()->get(DesignLinter::class)->lint($document, self::context());
    }

    private static function context(): LintContext
    {
        return new LintContext(
            self::projectId(),
            new CompilationContext([self::FAMILY, self::UNMEASURABLE_FAMILY], []),
            [self::BRAND_PRIMARY, self::BRAND_SECONDARY],
        );
    }

    /**
     * @param list<DesignElement> $elements
     */
    private static function document(array $elements): DesignDocument
    {
        return new DesignDocument(new CanvasSpec(self::CANVAS_SIDE, self::CANVAS_SIDE), $elements);
    }

    private static function text(
        string $id,
        float $x,
        float $y,
        float $width,
        string $text = 'Nová kolekce',
        string $font = self::FAMILY,
        float $size = 96.0,
        string $color = self::BRAND_PRIMARY,
        null|int $maxLength = null,
        null|string $sampleValue = null,
        bool $richText = false,
        bool $uppercase = false,
    ): TextElement {
        return new TextElement(
            id: $id,
            text: $text,
            font: $font,
            size: $size,
            color: $color,
            align: TextAlign::Left,
            lineHeight: TextElement::DEFAULT_LINE_HEIGHT,
            placement: new Placement(null, $x, $y, $width),
            input: new TextInputSpec(
                name: $id,
                maxLength: $maxLength,
                uppercase: $uppercase,
                richText: $richText,
                sampleValue: $sampleValue,
            ),
        );
    }

    private static function image(
        string $id,
        float $x,
        float $y,
        float $width,
        float $height,
        bool $placeholder,
        null|string $assetId = null,
    ): ImageElement {
        return new ImageElement(
            id: $id,
            assetId: $assetId,
            placement: new Placement(null, $x, $y, $width, $height),
            input: $placeholder ? new ImageInputSpec(name: $id) : null,
        );
    }

    /**
     * @param list<string> $memberIds
     * @param list<string> $childIds
     */
    private static function container(
        string $id,
        array $memberIds,
        array $childIds = [],
        null|float $maxHeight = 400.0,
        null|float $gap = null,
        null|float $spaceAfter = null,
    ): ContainerElement {
        return new ContainerElement($id, $memberIds, $childIds, $maxHeight, $gap, $spaceAfter);
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
        $contents = file_get_contents(self::getContainer()->getParameter('kernel.project_dir') . '/public/theme/fonts/Nunito-Regular.ttf');
        self::assertIsString($contents);

        return $contents;
    }

    private static function projectId(): UuidInterface
    {
        return Uuid::fromString(TestDataFixture::PROJECT_1_ID);
    }
}
