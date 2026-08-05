<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

/**
 * A designer-authored container ("smart text area"): its members reflow
 * vertically at render time — a filled text that wraps to more lines pushes
 * the flow items below it down, hidden items collapse. For a TOP-LEVEL
 * container the flow is bounded by maxHeight measured from `y` downward (both
 * in canvas px, the same coordinate space as `inputs[].frame`); an export
 * whose content exceeds the bound is rejected with 400 `container_overflow`.
 *
 * Containers can NEST: `memberContainerIds` lists child containers that flow
 * inside this one as single items (laid out first, shifted as a whole). A
 * container with `nested: true` grows freely with its content — its own
 * maxHeight is NOT enforced; only the outermost container of its tree gates
 * the overflow error.
 *
 * `gap` (canvas px) replaces the designed gaps between consecutive flow items
 * with a uniform spacing when non-null; null means the designed gaps are
 * preserved.
 *
 * TOP-LEVEL containers additionally COLLISION-PUSH each other at render time:
 * walked in designed-top order, a container whose content would run into a
 * lower, horizontally-overlapping container pushes it (and everything under
 * it, chained) down by the excess — designed whitespace absorbs growth first
 * and there is no pull-up. `spaceAfter` (canvas px, null = 0) is the
 * container's guaranteed clearance below: the landing distance when it
 * pushes, and its margin against the canvas bottom — content ending below
 * `variant.height − spaceAfter` is a `container_overflow` 400 too.
 *
 * `memberInputIds` lists the FILLABLE member text inputs in flow order (top
 * to bottom); each listed input also carries this container's id as its
 * `containerId`. A container may additionally hold decorative images (icons /
 * separators) that ride the flow server-side but are not fillable and are
 * therefore not listed here — a consumer mirroring the reflow approximately
 * should treat the server-rendered preview as authoritative.
 * The reflow algorithm is documented in docs/api/consumer-prompt.md.
 */
final readonly class TemplateVariantContainerResponse
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
        public array $memberContainerIds = [],
        public null|float $gap = null,
        public null|float $spaceAfter = null,
        public bool $nested = false,
    ) {
    }
}
