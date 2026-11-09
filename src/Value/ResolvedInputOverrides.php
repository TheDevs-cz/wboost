<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * Override maps for a render call, keyed by the canvas object's `inputId`
 * UUID. Replaces the legacy positional binding (`canvas.getObjects('textbox')[i]`
 * ↔ `variant.inputs[i]`) — see Stage 2 of the Fabric migration.
 */
readonly final class ResolvedInputOverrides
{
    /**
     * @param array<string, string> $texts inputId → replacement text. For a
     *   rich-text override this holds the PLAIN concatenation (the projection
     *   maxLength/uppercase were applied to) so every plain-text consumer
     *   keeps working; the styled runs live in $richTexts under the same key.
     * @param array<string, bool> $hidden inputId → true when the textbox should be hidden.
     * @param array<string, RichText> $richTexts inputId → styled runs, present
     *   only for rich-text inputs whose value actually carries styling.
     * @param array<string, string> $fonts inputId → the face family the WHOLE
     *   textbox renders in (the user's font choice — validated against the
     *   input's font options). Applied BEFORE the text override, so a rich
     *   value's unstyled runs inherit it and a list stack takes it as its
     *   base font.
     * @param array<string, string> $shapeColors shape inputId → the `#rrggbb`
     *   the user repainted a recolourable shape in ({@see ShapeColorInput}),
     *   already validated by
     *   {@see \WBoost\Web\Services\SocialNetwork\ResolveShapeColorOverrides}.
     *   Baked into the canvas JSON server-side before the render.
     * @param list<string> $hiddenContainerIds container-hide REQUESTS: ids a
     *   caller asked to hide (`{hide: true}` / a `hiddenValues[…]` checkbox)
     *   that name no input of the variant — candidate CONTAINER ids. Not
     *   validated here: the renderer honours only those naming a HIDABLE
     *   container of the variant it renders and expands them into the
     *   members' hide overrides ({@see CanvasContainer::hiddenMemberInputIds()});
     *   the strict API path refuses a non-hidable container up front
     *   ({@see \WBoost\Web\Services\SocialNetwork\ValidateContainerHides}).
     * @param array<string, int> $rowCounts repeatable row container id → how
     *   many of its rows the fill shows (container-rows P2). Not validated
     *   here: the hide expansion clamps each to the row's `[min, max]` and a
     *   row it does not name shows its designed default
     *   ({@see \WBoost\Web\Services\Template\CanvasContainer::inactiveRowMemberIds()}).
     */
    public function __construct(
        public array $texts,
        public array $hidden,
        public array $richTexts = [],
        public array $fonts = [],
        public array $shapeColors = [],
        public array $hiddenContainerIds = [],
        public array $rowCounts = [],
    ) {
    }

    /**
     * @param array<string, string> $shapeColors
     */
    public function withShapeColors(array $shapeColors): self
    {
        return new self($this->texts, $this->hidden, $this->richTexts, $this->fonts, $shapeColors, $this->hiddenContainerIds, $this->rowCounts);
    }

    /**
     * @param array<string, int> $rowCounts
     */
    public function withRowCounts(array $rowCounts): self
    {
        return new self($this->texts, $this->hidden, $this->richTexts, $this->fonts, $this->shapeColors, $this->hiddenContainerIds, $rowCounts);
    }

    /**
     * The per-object hide map the render applies: the inputs' own hides plus
     * every deep member of each requested HIDABLE container. A container hide
     * WINS over a member's own "shown" state, and leaves that state untouched
     * (un-hiding the container restores it).
     *
     * Plus the UNUSED rows of every repeatable row (container-rows P2): the
     * deep members of each materialised slot beyond the fill's row count. An
     * unused row wins over everything — it is not part of the fill at all.
     *
     * @param list<CanvasContainer> $containers the rendered variant's containers
     * @return array<string, bool>
     */
    public function effectiveHidden(array $containers): array
    {
        $hidden = $this->hidden;

        foreach (CanvasContainer::hiddenMemberInputIds($containers, $this->hiddenContainerIds) as $inputId) {
            $hidden[$inputId] = true;
        }

        foreach (CanvasContainer::inactiveRowMemberIds($containers, $this->rowCounts) as $inputId) {
            $hidden[$inputId] = true;
        }

        return $hidden;
    }
}
