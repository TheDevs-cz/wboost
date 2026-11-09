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
 * `containerId`. `memberImageInputIds` (2026-11) lists the fillable IMAGE
 * slots that flow in it — a fixed box each (their `imageInputs[].frame`),
 * moved by the texts above, collapsing when hidden; each such image input
 * carries this container's id as its `containerId`. A container may
 * additionally hold decorative images (icons / separators) that ride the flow
 * server-side but are not fillable and are therefore not listed here — a
 * consumer mirroring the reflow approximately should treat the
 * server-rendered preview as authoritative.
 * The reflow algorithm is documented in docs/api/consumer-prompt.md.
 *
 * `hidable: true` → the designer allows hiding the WHOLE container: offer a
 * toggle (captioned with `name`, null = unnamed) and send
 * `inputs[<container id>] = { "hide": true }` on export/preview. The container
 * and every member of its tree (texts, decorative images, shapes, nested
 * containers) then disappear and collapse like hidden members; the members'
 * own hide states are kept and apply again once the container is shown.
 * Hiding a non-hidable container is a 400 `container_not_hidable`. A
 * hidable container holding only decorative images / shapes is listed too
 * (toggle only): empty `memberInputIds`, `y` = its highest decorative member,
 * never referenced from a parent's `memberContainerIds`.
 *
 * `repeat: {max, min, default}` (null on a plain container) marks a
 * repeatable ROW — a nested container the user may repeat; `fitsRows` is
 * how many fit by designed geometry. Only the template row is listed here;
 * `variants[].repeatableRows[]` names its fillable members and the ids of
 * every row, and the render bodies fill it through `rows`.
 */
final readonly class TemplateVariantContainerResponse
{
    /**
     * @param list<string> $memberInputIds
     * @param list<string> $memberContainerIds
     * @param list<string> $memberImageInputIds
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
        public bool $hidable = false,
        public null|string $name = null,
        public array $memberImageInputIds = [],
        /**
         * @var null|array{max: int, min: int, default: int}
         */
        public null|array $repeat = null,
        public null|int $fitsRows = null,
    ) {
    }
}
