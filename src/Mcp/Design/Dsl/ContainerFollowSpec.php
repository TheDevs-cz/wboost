<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

/**
 * "Navázat pod" in the design DSL: `"follows": {"container": "<slug>", "gap": 20}`
 * on a TOP-LEVEL container attaches it below another top-level container —
 * at render time it starts `gap` px below the target's ACTUAL content bottom
 * (pulled up and pushed down). Compiles to the canvas
 * {@see \WBoost\Web\Value\ContainerFollow} (`{containerId, gap}`); the
 * container's canvas id IS its slug, so `container` maps 1:1.
 *
 * The parser checks the shape only — that the target is a container, that
 * both ends are top-level and that no chain loops back need the whole
 * document and are lint errors (`follows_unknown_container`,
 * `follows_nested`, `follows_cycle`), because the canvas sanitizer would
 * silently drop such a link.
 */
readonly final class ContainerFollowSpec
{
    /** The editor's `DEFAULT_FOLLOW_GAP` (assets/controllers/container_follow.js). */
    public const float DEFAULT_GAP = 20.0;

    public function __construct(
        /** Slug of the target container. */
        public string $containerId,
        /**
         * Px between the target's content bottom and this container's top.
         * Null = the default: the target's uniform `gap` if it has one, else
         * {@see self::DEFAULT_GAP} (≡ the editor's `defaultFollowGap`).
         */
        public null|float $gap = null,
    ) {
    }

    /**
     * The gap the link gets, resolving the default against the target.
     */
    public function resolvedGap(null|ContainerElement $target): float
    {
        if ($this->gap !== null) {
            return $this->gap;
        }

        $targetGap = $target?->gap;

        return $targetGap !== null && is_finite($targetGap) && $targetGap >= 0.0 ? $targetGap : self::DEFAULT_GAP;
    }

    /**
     * @return array{container: string, gap: null|float}
     */
    public function toArray(): array
    {
        return ['container' => $this->containerId, 'gap' => $this->gap];
    }
}
