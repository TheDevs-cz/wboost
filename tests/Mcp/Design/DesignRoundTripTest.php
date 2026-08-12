<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Design;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Mcp\Design\CompiledDesign;
use WBoost\Web\Mcp\Design\DecompiledDesign;
use WBoost\Web\Mcp\Design\DesignCompiler;
use WBoost\Web\Mcp\Design\DesignDecompiler;
use WBoost\Web\Mcp\Design\DesignLoss;
use WBoost\Web\Mcp\Design\Dsl\BackgroundElement;
use WBoost\Web\Mcp\Design\Dsl\DslParser;
use WBoost\Web\Services\Editor\BackgroundLayer;
use WBoost\Web\Value\BackgroundMode;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;

/**
 * `canvas → DSL → canvas` over **every canvas in `tests/DataFixtures`** (read
 * out of the fixture database, so the set stays exhaustive as fixtures grow)
 * plus five REAL canvases exported from production and anonymized into
 * `Fixtures/canvases/`.
 *
 * ## The plan's acceptance criterion, and the honest version of it
 *
 * S4-T5 says the round trip must produce *"a canvas that renders identically"*.
 * That is **not achievable as written**, and no amount of care in the
 * decompiler would make it so: DSL v1 is a deliberately small grammar and the
 * canvases people actually have contain things it has no word for — a
 * design-hidden layer, a background that is not a gallery picture, the
 * list/checklist input stack, ruler guides. A decompiler that reproduced them
 * would have to be a Fabric serializer, which is exactly what plan §0.3 says
 * the DSL exists to avoid.
 *
 * So the criterion is split into three, and all three are asserted here:
 *
 * 1. **Exact where it can be exact.** A canvas the decompiler reports NO loss
 *    for must round-trip with a byte-for-byte identical projection (floats
 *    within 0.01). No exceptions, no allowances.
 * 2. **Faithful everywhere.** For EVERY canvas, loss or not, the objects that
 *    survive must come back with identical geometry, identical text, identical
 *    input contract and identical container definitions
 *    ({@see CanvasComparison} owns exactly what that means and what it
 *    excludes). A loss explains a MISSING thing; it never excuses a moved one.
 * 3. **Idempotent everywhere.** `decompile(compile(decompile(c)))` equals
 *    `decompile(c)`, document and identity map alike — so `get_design →
 *    set_design → get_design` converges after one step instead of drifting for
 *    as long as an agent keeps editing. This is the property that actually
 *    protects a design under repeated agent edits, and it holds for canvases
 *    the DSL cannot fully express too.
 *
 * ## The loss table is pinned, not tolerated
 *
 * {@see LOSSES} names, per canvas, exactly which loss codes the decompiler is
 * expected to report. A canvas that starts losing something new fails; a
 * canvas that stops losing something fails too and the table is updated. That
 * is what stops "report the real coverage number" from decaying into "whatever
 * it happens to be today".
 */
final class DesignRoundTripTest extends KernelTestCase
{
    private const string CANVAS_FIXTURE_DIR = __DIR__ . '/Fixtures/canvases';

    /**
     * The expected loss codes per canvas, as a sorted, deduplicated list.
     *
     * Keys are the `TemplateVariant` UUID (fixture database) or the fixture
     * file's basename (real exported canvases). An empty list means the canvas
     * is fully expressible in DSL v1 and must round-trip exactly.
     *
     * @var array<string, list<string>>
     */
    private const array LOSSES = [
        // ---- tests/DataFixtures (every TemplateVariant row, empty canvases
        //      included — the enumeration below is `findAll()`) -------------

        // "Insta Template 1" / "Custom Template 1": the same design over a
        // preset and a print dimension. Legacy canvas-mode background (a note,
        // not a loss), a placeholder stand-in that is not a gallery row, and a
        // per-input description.
        '00000000-0000-0000-0000-000000000031' => ['asset_unresolved', 'canvas_feature_dropped', 'input_feature_dropped'],
        '00000000-0000-0000-0000-000000000081' => ['asset_unresolved', 'canvas_feature_dropped', 'input_feature_dropped'],

        // The cross-user variants: an EMPTY canvas carrying one input, which
        // therefore binds to no textbox and cannot survive into a document.
        '00000000-0000-0000-0000-000000000033' => ['canvas_feature_dropped', 'object_dropped'],
        '00000000-0000-0000-0000-000000000083' => ['canvas_feature_dropped', 'object_dropped'],

        // The template group's two member variants, and the manually-added
        // variant on the grouped template (canvas `{}` — never edited).
        '00000000-0000-0000-0000-0000000000c2' => ['canvas_feature_dropped'],
        '00000000-0000-0000-0000-0000000000c4' => ['canvas_feature_dropped'],
        '00000000-0000-0000-0000-0000000000c5' => ['canvas_feature_dropped'],

        // "Orientation Template" — the feature-complete variant: a
        // design-hidden textbox (object_dropped), a per-input description plus
        // the lists / checkbox-list / checklist stack (input_feature_dropped),
        // and a fillable background whose picture is not a gallery row
        // (asset_unresolved).
        '00000000-0000-0000-0000-000000000102' => ['asset_unresolved', 'input_feature_dropped', 'object_dropped'],

        // "Blank Canvas" — the MCP design tools' write target. An EMPTY
        // layer-mode canvas with no inputs: nothing to express, nothing to
        // lose. The one entry in this table that MUST stay empty, because it is
        // the only starting point where `set_design` writes without an
        // acknowledgement (see DesignOverwriteGuard).
        '00000000-0000-0000-0000-000000000111' => [],

        // "Form Background" — the data-loss fixture, shaped like the
        // production case: a background uploaded through the add/edit-variant
        // form (no `file_upload` row, so `asset_unresolved`), stored at stack
        // index 1 rather than 0 (`object_restacked`) exactly as real canvases
        // do it.
        '00000000-0000-0000-0000-000000000113' => ['asset_unresolved', 'object_restacked'],

        // ---- real exported canvases (anonymized, see Fixtures/canvases) ----

        // Legacy canvas-mode designs whose every object IS expressible: the
        // only entry is the note that their background lives on the variant
        // row rather than in the document.
        'canvas-container-two-slots' => ['canvas_feature_dropped'],
        'canvas-decorative-and-slot' => ['canvas_feature_dropped'],

        // The volume case: 38 objects, an editor-locked decorative image and
        // 27 inputs carrying descriptions, plus a ruler guide.
        'canvas-text-heavy' => ['canvas_feature_dropped', 'input_feature_dropped', 'style_dropped'],

        // A layer-mode design whose background layer is design-HIDDEN (so it
        // is dropped before its out-of-place stack index is even reached) and
        // whose decorative image is editor-locked.
        'layer-background-out-of-place' => ['object_dropped', 'style_dropped'],

        // A layer-mode design whose background was uploaded through the
        // variant form: it has no `file_upload` row, so the DSL cannot name it.
        'layer-print-a4' => ['asset_unresolved'],

        // Every shape kind the editor's picker makes, straight out of a real
        // browser session: both gradient types, all three stroke styles, a
        // corner radius, a partial opacity and an editor-locked layer — plus
        // two texts, so the positional textbox binding has something to get
        // wrong. Lossless, and the ONLY browser-authored canvas here that is:
        // shapes were designed against what the editor already writes rather
        // than the other way round.
        'layer-shapes' => [],
    ];

    /**
     * Every canvas the fixture database holds — enumerated, never cherry-picked,
     * so a fixture added later is covered by this test the day it lands.
     */
    public function testEveryCanvasInTheDataFixturesRoundTrips(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        /** @var list<TemplateVariant> $variants */
        $variants = $entityManager->getRepository(TemplateVariant::class)->findAll();

        self::assertNotEmpty($variants, 'The fixture database has no template variants to round-trip.');

        $checked = 0;

        foreach ($variants as $variant) {
            /** @var mixed $canvas */
            $canvas = json_decode($variant->canvas, true);

            if (!is_array($canvas)) {
                $canvas = [];
            }


            $this->assertRoundTrip(
                $variant->id->toString(),
                $canvas,
                array_values($variant->inputs),
                array_values($variant->imageInputs),
                $variant->dimension->width(),
                $variant->dimension->height(),
                $variant->backgroundMode,
            );

            $checked++;
        }

        self::assertSame(
            10,
            $checked,
            'The fixture database gained or lost a template variant; add it to the loss table.',
        );
    }

    #[DataProvider('realCanvases')]
    public function testRealExportedCanvasRoundTrips(string $name): void
    {
        $fixture = self::readCanvasFixture($name);

        $this->assertRoundTrip(
            $name,
            $fixture['canvas'],
            $fixture['inputs'],
            $fixture['imageInputs'],
            $fixture['width'],
            $fixture['height'],
            $fixture['backgroundMode'],
        );
    }

    /**
     * The coverage number, asserted rather than narrated: of the canvases under
     * test, exactly these are fully expressible in DSL v1 and round-trip
     * byte-for-byte, and the rest are lossy for the reasons {@see LOSSES}
     * names. Written as an assertion so nobody can improve the number by
     * deleting a fixture.
     */
    public function testCoverageIsExactlyWhatTheLossTableClaims(): void
    {
        $lossless = 0;
        $noteOnly = 0;
        $destructive = 0;

        foreach (self::lossTable() as $codes) {
            if ($codes === []) {
                $lossless++;
            } elseif ($codes === ['canvas_feature_dropped']) {
                // The legacy canvas-level background: the DSL cannot address
                // it, and it survives untouched (see DesignLoss::$destructive).
                $noteOnly++;
            } else {
                $destructive++;
            }
        }

        self::assertCount(16, self::lossTable(), 'The loss table gained or lost a canvas.');
        self::assertSame(
            2,
            $lossless,
            'Two canvases under test are fully expressible: the EMPTY layer-mode write target ("Blank Canvas", …111), which has no design to lose, and "layer-shapes" — a browser-authored canvas of vector shapes, whose grammar was written against what the editor already emits.',
        );
        self::assertSame(5, $noteOnly, 'Canvases whose only entry is the non-destructive canvas-mode background note.');
        self::assertSame(9, $destructive, 'Canvases that lose something a set_design would DESTROY.');
    }

    /**
     * The fixed point, and the non-vacuous half of "exact where it can be
     * exact": a canvas the COMPILER produced is fully expressible, so its
     * decompilation is lossless — for every canvas under test, including the
     * ones whose original form is not. That is what makes the losses a
     * one-time toll on adopting the DSL rather than an erosion that repeats
     * on every edit. Asserted inside {@see assertRoundTrip()}, step (f).
     */
    /**
     * {@see LOSSES}, behind a signature that hides its literal shape — the
     * counting above is a real check, not one PHPStan can fold away.
     *
     * @return array<string, list<string>>
     */
    private static function lossTable(): array
    {
        return self::LOSSES;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function realCanvases(): iterable
    {
        $files = glob(self::CANVAS_FIXTURE_DIR . '/*.json');
        self::assertIsArray($files);
        self::assertNotEmpty($files, 'No real exported canvases are committed under Fixtures/canvases.');

        foreach ($files as $file) {
            $name = basename($file, '.json');

            yield $name => [$name];
        }
    }

    // -----------------------------------------------------------------
    // the harness
    // -----------------------------------------------------------------

    /**
     * @param array<array-key, mixed> $canvas
     * @param list<EditorTextInput> $textInputs
     * @param list<EditorImageInput> $imageInputs
     */
    private function assertRoundTrip(
        string $name,
        array $canvas,
        array $textInputs,
        array $imageInputs,
        int $width,
        int $height,
        BackgroundMode $backgroundMode,
    ): void {
        $decompiler = new DesignDecompiler();
        $compiler = new DesignCompiler(new BackgroundLayer());

        $assets = CanvasComparison::assets($canvas);

        $decompiled = $decompiler->decompile(
            $canvas,
            $textInputs,
            $imageInputs,
            $width,
            $height,
            $backgroundMode,
            CanvasComparison::decompilationContext($assets),
        );

        // (a) the document is valid DSL — the parser is strict, and a document
        // `get_design` shows must be one `set_design` accepts unchanged.
        $reparsed = DslParser::parse($decompiled->document->toArray());
        self::assertSame(
            $decompiled->document->toArray(),
            $reparsed->toArray(),
            sprintf('%s: the decompiled document does not survive a parse.', $name),
        );

        // (b) the pinned loss list.
        self::assertSame(
            self::lossTable()[$name] ?? ['<missing from the loss table>'],
            self::lossCodes($decompiled),
            sprintf(
                "%s: unexpected losses.\n%s",
                $name,
                implode("\n", array_map(
                    static fn (DesignLoss $loss): string => '  - [' . $loss->code->value . '] ' . $loss->message,
                    $decompiled->losses,
                )),
            ),
        );

        $compiled = $compiler->compile(
            $decompiled->document,
            CanvasComparison::compilationContext($decompiled->document, $assets),
            $decompiled->identity(),
        );

        // (c) faithful everywhere: whatever survived came back unchanged.
        $containerIds = CanvasComparison::containerIds($decompiled->document);
        $differences = CanvasComparison::diff(
            CanvasComparison::project($canvas, $textInputs, $imageInputs, $containerIds, $assets),
            CanvasComparison::project(
                $compiled->canvas,
                $compiled->textInputs,
                $compiled->imageInputs,
                $containerIds,
                CanvasComparison::assets($compiled->canvas),
            ),
            $name,
        );

        self::assertSame([], $differences, sprintf(
            "%s: the round trip changed the design.\n%s",
            $name,
            implode("\n", $differences),
        ));

        // (d) identity: every inputId the design carried is still carried.
        self::assertIdentityPreserved($name, $decompiled, $compiled);

        // (e) the fixed point. The FIRST round trip is where the losses land —
        // a design-hidden layer is gone, a background the DSL cannot name is
        // gone — so `decompile(c)` and `decompile(compile(decompile(c)))`
        // legitimately differ by exactly that. What must hold, and what
        // protects a design under repeated agent edits, is that the loop
        // CLOSES there: from the saved canvas on, decompiling and recompiling
        // changes nothing at all.
        $again = $decompiler->decompile(
            $compiled->canvas,
            $compiled->textInputs,
            $compiled->imageInputs,
            $width,
            $height,
            // A compiled canvas is always a layer-mode document; the variant's
            // own mode does not change, but the canvas-level background note
            // is about the variant, not about these objects.
            BackgroundMode::Layer,
            CanvasComparison::decompilationContext(CanvasComparison::assets($compiled->canvas)),
        );

        $recompiled = $compiler->compile(
            $again->document,
            CanvasComparison::compilationContext($again->document, CanvasComparison::assets($compiled->canvas)),
            $again->identity(),
        );

        $third = $decompiler->decompile(
            $recompiled->canvas,
            $recompiled->textInputs,
            $recompiled->imageInputs,
            $width,
            $height,
            BackgroundMode::Layer,
            CanvasComparison::decompilationContext(CanvasComparison::assets($recompiled->canvas)),
        );

        self::assertEquals(
            $again->document->toArray(),
            $third->document->toArray(),
            sprintf('%s: a second get_design → set_design → get_design changed the document — the loop does not converge.', $name),
        );
        self::assertSame(
            $again->inputIdsBySlug,
            $third->inputIdsBySlug,
            sprintf('%s: the slug to inputId mapping drifted on the third pass.', $name),
        );

        // Nothing DESTRUCTIVE was lost? Then the first round trip changed
        // nothing either, and the document an agent was shown is the document
        // that comes back.
        if ($decompiled->destructiveLosses() === []) {
            self::assertEquals(
                $decompiled->document->toArray(),
                $again->document->toArray(),
                sprintf('%s: nothing destructive was reported, yet the round trip changed the document.', $name),
            );
            self::assertSame(
                $decompiled->inputIdsBySlug,
                $again->inputIdsBySlug,
                sprintf('%s: nothing destructive was reported, yet the slug to inputId mapping drifted.', $name),
            );
        }

        // (f) the fixed point is LOSSLESS: whatever the DSL could not carry
        // out of the original is gone after one save, and nothing further is
        // lost by editing the design from then on.
        self::assertSame([], $again->lossesToArray(), sprintf(
            '%s: the round-tripped canvas STILL loses something. A canvas the compiler produced is by construction expressible, so this means the decompiler reports a loss the compiler never caused.',
            $name,
        ));
    }

    /**
     * Plan §4.1: the whole reason the DSL has agent-chosen slug ids. Every
     * `inputId` the decompiled document knows about must come back out of the
     * compiler on the SAME slug — a fresh UUID anywhere here means every saved
     * fill, every API consumer's `inputs[].id` and every container membership
     * for that element stops resolving, with nothing throwing.
     *
     * Identity lives on the canvas OBJECT for every element and additionally
     * in `inputs[]` / `imageInputs[]` for the fillable ones — a decorative
     * image and a non-fillable background have an `inputId` and no input row,
     * and theirs matters too: containers address members by it.
     */
    private static function assertIdentityPreserved(
        string $name,
        DecompiledDesign $decompiled,
        CompiledDesign $compiled,
    ): void {
        /** @var list<string> $compiledIds */
        $compiledIds = [];

        foreach ($compiled->objects() as $object) {
            /** @var mixed $inputId */
            $inputId = $object['inputId'] ?? null;

            if (is_string($inputId)) {
                $compiledIds[] = $inputId;
            }
        }

        foreach ($compiled->textInputs as $input) {
            self::assertContains($input->inputId, $compiledIds, sprintf(
                '%s: inputs[] carries %s, which no canvas object does.',
                $name,
                $input->inputId,
            ));
        }

        foreach ($compiled->imageInputs as $input) {
            self::assertContains($input->inputId, $compiledIds, sprintf(
                '%s: imageInputs[] carries %s, which no canvas object does.',
                $name,
                $input->inputId,
            ));
        }

        foreach ($decompiled->document->drawableElements() as $element) {
            $expected = $decompiled->inputIdsBySlug[$element->id] ?? null;

            if ($expected === null) {
                continue; // the canvas gave this element no id; the compiler mints one
            }

            if ($element instanceof BackgroundElement && $element->assetId === null) {
                // A background the DSL cannot name compiles to no object at
                // all — `asset_unresolved` says so — so there is nothing left
                // to carry an id.
                continue;
            }

            self::assertContains($expected, $compiledIds, sprintf(
                '%s: element "%s" lost its inputId %s on the way back.',
                $name,
                $element->id,
                $expected,
            ));
        }
    }

    /**
     * @return list<string>
     */
    private static function lossCodes(DecompiledDesign $decompiled): array
    {
        $codes = array_map(
            static fn (DesignLoss $loss): string => $loss->code->value,
            $decompiled->losses,
        );

        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }

    /**
     * @return array{width: int, height: int, backgroundMode: BackgroundMode, canvas: array<array-key, mixed>, inputs: list<EditorTextInput>, imageInputs: list<EditorImageInput>}
     */
    private static function readCanvasFixture(string $name): array
    {
        $raw = file_get_contents(self::CANVAS_FIXTURE_DIR . '/' . $name . '.json');
        self::assertIsString($raw);

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var mixed $canvas */
        $canvas = $decoded['canvas'] ?? null;
        self::assertIsArray($canvas);

        /** @var mixed $rawInputs */
        $rawInputs = $decoded['inputs'] ?? [];
        self::assertIsArray($rawInputs);

        /** @var mixed $rawImageInputs */
        $rawImageInputs = $decoded['imageInputs'] ?? [];
        self::assertIsArray($rawImageInputs);

        /** @var list<EditorTextInput> $inputs */
        $inputs = [];

        foreach ($rawInputs as $entry) {
            self::assertIsArray($entry);
            /** @phpstan-ignore argument.type */
            $inputs[] = EditorTextInput::fromArray($entry);
        }

        /** @var list<EditorImageInput> $imageInputs */
        $imageInputs = [];

        foreach ($rawImageInputs as $entry) {
            self::assertIsArray($entry);
            /** @phpstan-ignore argument.type */
            $imageInputs[] = EditorImageInput::fromArray($entry);
        }

        /** @var mixed $width */
        $width = $decoded['width'] ?? null;
        /** @var mixed $height */
        $height = $decoded['height'] ?? null;
        /** @var mixed $mode */
        $mode = $decoded['backgroundMode'] ?? null;

        self::assertIsInt($width);
        self::assertIsInt($height);
        self::assertIsString($mode);

        return [
            'width' => $width,
            'height' => $height,
            'backgroundMode' => BackgroundMode::from($mode),
            'canvas' => $canvas,
            'inputs' => $inputs,
            'imageInputs' => $imageInputs,
        ];
    }
}
