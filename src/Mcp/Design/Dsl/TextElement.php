<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

use WBoost\Web\Value\ElementMoveAllowance;

/**
 * A text element — one Fabric Textbox plus its {@see \WBoost\Web\Value\EditorTextInput}.
 *
 * {@see $text} is the DESIGNED stand-in: the copy the designer sees in the
 * editor and what renders when nothing was filled and no
 * {@see TextInputSpec::$sampleValue} was authored.
 *
 * {@see $font} must be an exact face string as `get_context` reports it —
 * `"Hero New (Hero New ExtraBold)"`, family plus face in parentheses, because
 * the canvas stores it verbatim and the render template registers `@font-face`
 * under exactly that name. **The parser does not validate it against the
 * project**: it has no project context by design. Membership of the project's
 * font list is the compiler's hard error / the linter's job (plan §4.2
 * invariant 10).
 *
 * There is deliberately no `height` key: Fabric computes a textbox's height
 * from its wrapped content and authoring one is forbidden (§4.2 invariant 6),
 * so `height` is simply not in the allowed key set and an agent that writes it
 * is told.
 */
readonly final class TextElement implements DesignElement
{
    public const string DEFAULT_COLOR = '#000000';
    public const float DEFAULT_LINE_HEIGHT = 1.16;
    public const int MIN_LETTER_SPACING = -200;
    public const int MAX_LETTER_SPACING = 1000;

    public function __construct(
        public string $id,
        public string $text,
        public string $font,
        /** Font size in canvas px, > 0. */
        public float $size,
        /** Lowercase `#rrggbb`. */
        public string $color,
        public TextAlign $align,
        /** Multiplier, > 0 (Fabric's `lineHeight`). */
        public float $lineHeight,
        public Placement $placement,
        public TextInputSpec $input,
        /**
         * Admin-set opacity, a 0…1 fraction like a shape's (Fabric's native
         * `opacity`). Fill-time values keep it: it is a design property, never
         * a user override.
         */
        public float $opacity = 1.0,
        /**
         * "Uživatel může posunout" (canvas `userMove`): the end user may move
         * the element along the allowed axes, up to the per-direction maxima
         * (px, null = anywhere on the artwork). Null = fixed in place.
         */
        public null|ElementMoveAllowance $userMove = null,
        /**
         * Tracking in thousandths of an em (Fabric's `charSpacing`), added
         * after every character; 0 = the face's own spacing. Em-relative, so
         * it survives a change of `size`.
         */
        public float $letterSpacing = 0.0,
    ) {
    }

    public function kind(): ElementKind
    {
        return ElementKind::Text;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $placement = $this->placement->toArray();

        $data = [
            'kind' => ElementKind::Text->value,
            'id' => $this->id,
            'text' => $this->text,
            'font' => $this->font,
            'size' => $this->size,
            'color' => $this->color,
            'align' => $this->align->value,
            'lineHeight' => $this->lineHeight,
            'at' => $placement['at'],
            'x' => $placement['x'],
            'y' => $placement['y'],
            'width' => $placement['width'],
            'input' => $this->input->toArray(),
            'opacity' => $this->opacity,
        ];

        if ($this->userMove !== null) {
            $data['userMove'] = $this->userMove->toArray();
        }

        // Only when set: a design without tracking reads exactly as before.
        if ($this->letterSpacing !== 0.0) {
            $data['letterSpacing'] = $this->letterSpacing;
        }

        return $data;
    }
}
