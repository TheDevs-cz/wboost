<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Editor;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Editor\EchoCapableTextInputs;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Value\EditorTextInput;

/**
 * The echo-capable set decides BOTH which texts the fill page draws
 * client-side AND which textboxes the base render blanks — the two must be
 * the same set, so every rule here protects a way the echo could lie on
 * screen (text over content that should cover it, a reflow the base cannot
 * follow, a block stack the echo cannot draw).
 *
 * @covers \WBoost\Web\Services\Editor\EchoCapableTextInputs
 */
final class EchoCapableTextInputsTest extends TestCase
{
    private const string ID_1 = '11111111-1111-4111-8111-111111111111';
    private const string ID_2 = '22222222-2222-4222-8222-222222222222';
    private const string ID_3 = '33333333-3333-4333-8333-333333333333';

    private function resolver(): EchoCapableTextInputs
    {
        return new EchoCapableTextInputs(new TextInputObjectBinder(new CanvasPlaceholderGeometry()));
    }

    private function input(
        string $inputId,
        bool $locked = false,
        bool $richText = false,
        bool $lists = false,
    ): EditorTextInput {
        return new EditorTextInput($inputId, 'Name', null, $locked, false, null, false, $richText, $lists);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function textbox(float $left, float $top, float $width, float $height, array $extra = []): array
    {
        return array_merge([
            'type' => 'Textbox',
            'left' => $left,
            'top' => $top,
            'width' => $width,
            'height' => $height,
        ], $extra);
    }

    public function testPlainUnobstructedTextsAreCapable(): void
    {
        $canvas = ['objects' => [
            $this->textbox(0, 0, 100, 40),
            $this->textbox(0, 100, 100, 40),
        ]];

        $result = $this->resolver()->resolve($canvas, [$this->input(self::ID_1), $this->input(self::ID_2)]);

        self::assertSame([self::ID_1, self::ID_2], $result);
    }

    public function testLockedAndListsInputsAreNever(): void
    {
        $canvas = ['objects' => [
            $this->textbox(0, 0, 100, 40),
            $this->textbox(0, 100, 100, 40),
            $this->textbox(0, 200, 100, 40),
        ]];

        $result = $this->resolver()->resolve($canvas, [
            $this->input(self::ID_1, locked: true),
            $this->input(self::ID_2, richText: true, lists: true),
            $this->input(self::ID_3, richText: true),
        ]);

        self::assertSame([self::ID_3], $result, 'locked = design, lists = block stack; plain rich stays capable');
    }

    public function testInputWithoutALocatableTextboxIsNever(): void
    {
        $canvas = ['objects' => [$this->textbox(0, 0, 100, 40)]];

        $result = $this->resolver()->resolve($canvas, [$this->input(self::ID_1), $this->input(self::ID_2)]);

        self::assertSame([self::ID_1], $result);
    }

    public function testDesignContentAboveAndOverlappingGuardsTheText(): void
    {
        $canvas = ['objects' => [
            $this->textbox(0, 0, 100, 40),
            // A visible image overlapping the textbox, painted above it.
            ['type' => 'Image', 'left' => 50, 'top' => 10, 'width' => 100, 'height' => 100, 'src' => 'x.png'],
        ]];

        self::assertSame([], $this->resolver()->resolve($canvas, [$this->input(self::ID_1)]));
    }

    public function testNonOverlappingOrHiddenOrBelowContentDoesNotGuard(): void
    {
        $canvas = ['objects' => [
            // Below the text in the stack — base content under the echo, fine.
            ['type' => 'Image', 'left' => 0, 'top' => 0, 'width' => 500, 'height' => 500, 'src' => 'bg.png'],
            $this->textbox(0, 0, 100, 40),
            // Above but elsewhere on the canvas.
            ['type' => 'Rect', 'left' => 400, 'top' => 400, 'width' => 50, 'height' => 50],
            // Above and overlapping, but design-hidden (layers-panel eye).
            ['type' => 'Image', 'left' => 0, 'top' => 0, 'width' => 200, 'height' => 200, 'visible' => false, 'src' => 'x.png'],
        ]];

        self::assertSame([self::ID_1], $this->resolver()->resolve($canvas, [$this->input(self::ID_1)]));
    }

    public function testAnotherEchoedTextAboveDoesNotGuardButADemotedOneDoes(): void
    {
        // ID_1 under ID_2 (overlapping); ID_2 under a guarding image. The
        // image demotes ID_2, and the now-baked ID_2 must then demote ID_1 —
        // the fixpoint pass.
        $canvas = ['objects' => [
            $this->textbox(0, 0, 100, 40),
            $this->textbox(0, 20, 100, 40),
            ['type' => 'Image', 'left' => 0, 'top' => 30, 'width' => 100, 'height' => 40, 'src' => 'x.png'],
        ]];

        $result = $this->resolver()->resolve($canvas, [$this->input(self::ID_1), $this->input(self::ID_2)]);

        self::assertSame([], $result);
    }

    public function testOverlappingEchoTextsStayCapableTogether(): void
    {
        $canvas = ['objects' => [
            $this->textbox(0, 0, 100, 40),
            $this->textbox(0, 20, 100, 40),
        ]];

        $result = $this->resolver()->resolve($canvas, [$this->input(self::ID_1), $this->input(self::ID_2)]);

        self::assertSame([self::ID_1, self::ID_2], $result, 'the echo canvas preserves their mutual z-order');
    }

    public function testRotatedContentAboveGuardsViaItsRotatedBoundingBox(): void
    {
        // A 200x10 bar anchored (top-left) at (150, 0), rotated 90°: unrotated
        // it would sit entirely right of the text; rotated it sweeps down over
        // x∈[140,150] — which still misses the text at x∈[0,100]. Anchor it at
        // (90, 0) instead: rotated it occupies x∈[80,90] ∩ text → guard.
        $canvas = ['objects' => [
            $this->textbox(0, 0, 100, 40),
            ['type' => 'Rect', 'left' => 90, 'top' => 0, 'width' => 200, 'height' => 10, 'angle' => 90],
        ]];

        self::assertSame([], $this->resolver()->resolve($canvas, [$this->input(self::ID_1)]));

        // The same bar at (150, 0) unrotated overlaps nothing after rotation.
        $canvas['objects'][1]['left'] = 150;
        self::assertSame([self::ID_1], $this->resolver()->resolve($canvas, [$this->input(self::ID_1)]));
    }

    public function testContainerTreeWithABakedMemberDemotesItsTexts(): void
    {
        $decorId = '44444444-4444-4444-8444-444444444444';
        $canvas = [
            'objects' => [
                $this->textbox(0, 0, 100, 40),
                $this->textbox(0, 100, 100, 40),
                ['type' => 'Image', 'left' => 0, 'top' => 500, 'width' => 20, 'height' => 20, 'inputId' => $decorId, 'src' => 'icon.png'],
                $this->textbox(0, 600, 100, 40),
            ],
            'containers' => [
                // Clean tree: both members are capable texts.
                ['id' => 'c-clean', 'maxHeight' => 300.0, 'memberInputIds' => [self::ID_1, self::ID_2]],
                // Dirty tree: a decorative image member would sit still in the
                // base while the echoed text reflows.
                ['id' => 'c-dirty', 'maxHeight' => 300.0, 'memberInputIds' => [self::ID_3, $decorId]],
            ],
        ];

        $result = $this->resolver()->resolve($canvas, [
            $this->input(self::ID_1),
            $this->input(self::ID_2),
            $this->input(self::ID_3),
        ]);

        self::assertSame([self::ID_1, self::ID_2], $result);
    }

    public function testNestedDirtyChildDemotesTheWholeRootTree(): void
    {
        $decorId = '44444444-4444-4444-8444-444444444444';
        $canvas = [
            'objects' => [
                $this->textbox(0, 0, 100, 40),
                $this->textbox(0, 100, 100, 40),
                ['type' => 'Image', 'left' => 0, 'top' => 200, 'width' => 20, 'height' => 20, 'inputId' => $decorId, 'src' => 'icon.png'],
            ],
            'containers' => [
                ['id' => 'root', 'maxHeight' => 500.0, 'memberInputIds' => [self::ID_1], 'memberContainerIds' => ['child']],
                ['id' => 'child', 'maxHeight' => 100.0, 'memberInputIds' => [self::ID_2, $decorId]],
            ],
        ];

        $result = $this->resolver()->resolve($canvas, [$this->input(self::ID_1), $this->input(self::ID_2)]);

        self::assertSame([], $result, "the child's baked icon poisons the whole root tree");
    }

    public function testMalformedObjectAboveIsConservative(): void
    {
        $canvas = ['objects' => [
            $this->textbox(0, 0, 100, 40),
            'garbage',
        ]];

        self::assertSame([], $this->resolver()->resolve($canvas, [$this->input(self::ID_1)]));
    }
}
