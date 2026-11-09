<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

/**
 * A repeatable container row ("Opakovatelný řádek", container-rows P5): a
 * NESTED container the designer drew ONCE that the user may show
 * `repeat.min`…`repeat.max` times (`repeat.default` on a fresh fill), each
 * copy flowing below the previous one inside `parentContainerId`.
 *
 * Fill it with the render bodies' `rows`:
 * `{"<containerId>": [ {"<members[].id>": value, …}, … ]}` — one object per
 * row, the list length is the count; values take the shapes of `inputs`
 * (text, `{hide: true}` for a container member), `images` (image slot) and
 * `shapeColors` (shape). A member left out shows the template row's sample.
 *
 * `fitsRows` is the DESIGNED-geometry estimate of how many rows fit the
 * parent's max height (sample texts, within `[min, max]`); real texts may
 * wrap — a body whose rows do not fit answers 400 `container_overflow`.
 *
 * `slots` lists, for every row up to `max` (index 0 = the template row with
 * its own ids), the id each member carries in that row — you never need to
 * derive them. You only need them for a group dimension's `placements` of a
 * row's picture, or to read a version's `fill` keyed by slot ids elsewhere.
 * Rows merged from hand-numbered copies keep those copies' OLD ids here.
 */
final readonly class TemplateVariantRepeatableRowResponse
{
    /**
     * @param array{max: int, min: int, default: int} $repeat
     * @param list<TemplateVariantRepeatableRowMemberResponse> $members
     * @param list<TemplateVariantRepeatableRowSlotResponse> $slots
     */
    public function __construct(
        public string $containerId,
        public null|string $parentContainerId,
        public null|string $name,
        public array $repeat,
        public null|int $fitsRows,
        public array $members,
        public array $slots,
    ) {
    }
}
