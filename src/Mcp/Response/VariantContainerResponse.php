<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * One container ("smart text area") of a variant: a group of members that
 * reflow VERTICALLY at render time — a filled text that wraps to more lines
 * pushes everything below it down, a hidden member collapses and takes no
 * space.
 *
 * This is why a fill can fail on length even when every value respected its
 * `maxLength`: content that does not fit the outermost container's
 * {@see $maxHeight} (measured from {@see $y} downward, canvas px) is a
 * rejected export, not an overflowing image. `spaceAfter` is the clearance the
 * container keeps below itself — against the next container and against the
 * page bottom — so it eats into the same budget.
 *
 * Containers NEST: {@see $memberContainerIds} are children that each flow as a
 * single item, and a child grows freely — only a container with
 * `nested: false` bounds anything. When a fill is refused for overflow, the
 * id reported is that outermost container.
 *
 * `memberInputIds` are in flow order (top to bottom) and list only the
 * FILLABLE members: containers may also carry decorative images that ride the
 * flow server-side, and design-hidden members that are not fillable at all.
 * The rendered image is the authority on how it ends up looking.
 */
readonly final class VariantContainerResponse
{
    /**
     * @param list<string> $memberInputIds
     * @param list<string> $memberContainerIds
     */
    public function __construct(
        public string $id,
        public float $maxHeight,
        public float $y,
        public array $memberInputIds,
        public array $memberContainerIds,
        public null|float $gap,
        public null|float $spaceAfter,
        public bool $nested,
    ) {
    }
}
