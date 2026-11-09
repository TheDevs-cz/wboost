<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * A repeatable container row ("Opakovatelný řádek") — the MCP twin of the
 * REST listing's `repeatableRows[]`
 * ({@see \WBoost\Web\Api\Templates\TemplateVariantRepeatableRowResponse},
 * field for field; DescribeVariantTest compares the two).
 *
 * The designer drew the row ONCE; the user may show `repeat.min`…`repeat.max`
 * copies (`repeat.default` when nothing is said), each flowing below the
 * previous one inside `parentContainerId`. Fill it with the `rows` argument
 * of render_variant / export_variant (export_document: per page):
 * `{"<containerId>": [ {"<members[].id>": value, …}, … ]}` — one object
 * per row, the list length is the count; values take the shapes of
 * `inputs` (text, `{hide: true}` for a container member), `images` (image
 * slot) and `shapeColors` (shape).
 *
 * `fitsRows` — how many rows fit by designed geometry (sample texts);
 * longer texts may fit fewer (export_variant then refuses the overflow).
 *
 * `slots` — per row up to `max` (index 0 = the template row), the id every
 * member carries in that row. Never needed to fill (`rows` takes the
 * template ids), only to read a fill keyed by slot ids.
 */
readonly final class VariantRepeatableRowResponse
{
    /**
     * @param array{max: int, min: int, default: int} $repeat
     * @param list<array{id: string, kind: string, name: null|string}> $members
     * @param list<array{index: int, containerId: string, ids: array<string, string>}> $slots
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
