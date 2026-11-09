<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Template;

use WBoost\Web\Value\CanvasContainer;

/**
 * A PHP twin of the shared layout engine (assets/editor/container_layout.js)
 * restricted to DESIGNED geometry: every text keeps its designed height, the
 * only variable is which members are hidden. That is enough to answer the
 * server-side questions the row model needs without a browser — "does the
 * flow still fit with one more row?" (the P4 add-row gate's server estimate,
 * the editor's "fits N rows" prefill) — while the fill page's client gate
 * keeps measuring the REAL wrapped heights.
 *
 * Mirrors the engine's rules: flow items are direct texts, child containers
 * (laid out first, one item each) and decorations (images / slots / shapes)
 * that overlap no text/child vertically (overlapping ones are attachments of
 * the item they overlap most); items sort by designed extent top; the gap
 * before an item is the container's uniform `gap` or the DESIGNED gap to its
 * designed predecessor (hidden or not), floored by a preceding child's
 * `spaceAfter`; hidden items take no space; a container whose items are all
 * hidden collapses; roots collision-push lower x-overlapping roots; a root
 * overflows past `maxHeight` from its anchor or past `canvasHeight −
 * spaceAfter`. Parity with the engine is pinned by `DesignedContainerFlowTest`
 * (node harness).
 */
final readonly class DesignedContainerFlow
{
    /**
     * Root container id → overflow in px (0 = fits) for the given hides.
     *
     * @param array<array-key, mixed> $canvas decoded canvas (textbox inputIds stamped)
     * @param array<string, bool> $hidden inputId → hidden (fill-time)
     * @return array<string, float>
     */
    public static function overflowByRoot(array $canvas, array $hidden, null|float $canvasHeight): array
    {
        $objects = is_array($canvas['objects'] ?? null) ? $canvas['objects'] : [];
        $containers = CanvasContainer::collectionFromCanvas($canvas);

        /** @var array<string, array<array-key, mixed>> $index */
        $index = [];
        foreach ($objects as $object) {
            if (!is_array($object) || !is_string($object['inputId'] ?? null) || isset($index[$object['inputId']])) {
                continue;
            }
            if (self::kind($object) !== null) {
                $index[$object['inputId']] = $object;
            }
        }

        $byId = [];
        foreach ($containers as $container) {
            $byId[$container->id] ??= $container;
        }

        $consumed = [];
        /** @var list<DesignedFlowNode> $roots */
        $roots = [];
        foreach ($containers as $container) {
            if ($container->isNestedIn($containers) || $container->maxHeight <= 0) {
                continue;
            }
            $node = self::buildNode($container, $byId, $index, $hidden, $consumed, []);
            if ($node !== null) {
                $roots[] = $node;
            }
        }

        foreach ($roots as $root) {
            self::measure($root);
        }

        // Sibling collision-push (designed-top order, x-overlap only).
        $ordered = $roots;
        usort($ordered, static fn (DesignedFlowNode $a, DesignedFlowNode $b): int => $a->anchorTop <=> $b->anchorTop);
        /** @var list<array{node: DesignedFlowNode, pushedBottom: float}> $placed */
        $placed = [];
        $result = [];
        foreach ($ordered as $root) {
            $delta = 0.0;
            foreach ($placed as $other) {
                $xOverlap = min($other['node']->extRight, $root->extRight) - max($other['node']->extLeft, $root->extLeft);
                if (!($xOverlap > 0)) {
                    continue;
                }
                $delta = max($delta, ($other['pushedBottom'] + ($other['node']->spaceAfter ?? 0.0)) - $root->anchorTop);
            }
            $delta = max(0.0, $delta);
            $placed[] = ['node' => $root, 'pushedBottom' => $root->contentBottom + $delta];

            $finalTop = $root->anchorTop + $delta;
            $finalBottom = $root->contentBottom + $delta;
            $overflow = max(0.0, $finalBottom - ($finalTop + $root->maxHeight));
            if ($canvasHeight !== null && $canvasHeight > 0) {
                $overflow = max($overflow, $finalBottom - ($canvasHeight - ($root->spaceAfter ?? 0.0)));
            }
            $result[$root->id] = $overflow;
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $object
     */
    private static function kind(array $object): null|string
    {
        $type = strtolower(is_string($object['type'] ?? null) ? $object['type'] : '');
        if ($type === 'textbox') {
            return 'text';
        }
        if ($type === 'image') {
            return ($object['isBackground'] ?? false) === true ? null : 'decoration';
        }

        return in_array($type, ['rect', 'circle', 'ellipse', 'triangle', 'polygon', 'polyline', 'line', 'path'], true) ? 'decoration' : null;
    }

    /**
     * @param array<array-key, mixed> $object
     */
    private static function num(array $object, string $key, float $default = 0.0): float
    {
        return is_numeric($object[$key] ?? null) ? (float) $object[$key] : $default;
    }

    /**
     * @param array<array-key, mixed> $object
     */
    private static function height(array $object): float
    {
        $scale = self::num($object, 'scaleY', 1.0);

        return self::num($object, 'height') * ($scale === 0.0 ? 1.0 : $scale);
    }

    /**
     * @param array<string, CanvasContainer> $byId
     * @param array<string, array<array-key, mixed>> $index
     * @param array<string, bool> $hidden
     * @param array<string, true> $consumed
     * @param array<string, true> $visiting
     */
    private static function buildNode(CanvasContainer $def, array $byId, array $index, array $hidden, array &$consumed, array $visiting): null|DesignedFlowNode
    {
        if (isset($visiting[$def->id]) || isset($consumed[$def->id])) {
            return null;
        }
        $visiting[$def->id] = true;

        $children = [];
        foreach ($def->memberContainerIds as $childId) {
            $child = $byId[$childId] ?? null;
            if ($child === null || $childId === $def->id) {
                continue;
            }
            $node = self::buildNode($child, $byId, $index, $hidden, $consumed, $visiting);
            if ($node !== null) {
                $children[] = $node;
            }
        }

        /** @var list<DesignedFlowItem> $items */
        $items = [];
        /** @var list<array{top: float, height: float, hidden: bool}> $decorations */
        $decorations = [];
        $extLeft = INF;
        $extRight = -INF;
        foreach ($def->memberInputIds as $memberId) {
            $object = $index[$memberId] ?? null;
            if ($object === null || ($object['visible'] ?? true) === false) {
                continue; // absent or DESIGN-hidden: not in the flow at all
            }
            $left = self::num($object, 'left');
            $extLeft = min($extLeft, $left);
            $extRight = max($extRight, $left + self::num($object, 'width') * self::num($object, 'scaleX', 1.0));

            $isHidden = ($hidden[$memberId] ?? false) === true;
            if (self::kind($object) === 'text') {
                $items[] = new DesignedFlowItem(self::num($object, 'top'), self::height($object), $isHidden);
            } else {
                $decorations[] = ['top' => self::num($object, 'top'), 'height' => self::height($object), 'hidden' => $isHidden];
            }
        }
        foreach ($children as $child) {
            $items[] = new DesignedFlowItem($child->designedExtTop, $child->designedExtBottom - $child->designedExtTop, false, $child);
            $extLeft = min($extLeft, $child->extLeft);
            $extRight = max($extRight, $child->extRight);
        }

        foreach ($decorations as $decoration) {
            $best = null;
            $bestOverlap = 0.0;
            foreach ($items as $item) {
                $overlap = min($decoration['top'] + $decoration['height'], $item->baseTop + $item->baseHeight) - max($decoration['top'], $item->baseTop);
                if ($overlap > 0 && $overlap > $bestOverlap) {
                    $best = $item;
                    $bestOverlap = $overlap;
                }
            }
            if ($best !== null) {
                $best->attachments[] = ['offset' => $decoration['top'] - $best->baseTop, 'height' => $decoration['height']];
            } else {
                $items[] = new DesignedFlowItem($decoration['top'], $decoration['height'], $decoration['hidden']);
            }
        }

        if ($items === []) {
            return null;
        }

        foreach ($items as $item) {
            $minOffset = 0.0;
            $attachedBottom = null;
            foreach ($item->attachments as $attachment) {
                $minOffset = min($minOffset, $attachment['offset']);
                $attachedBottom = max($attachedBottom ?? -INF, $attachment['offset'] + $attachment['height']);
            }
            $item->baseTopOffset = -$minOffset;
            $item->attachedBottom = $attachedBottom === null ? null : -$minOffset + $attachedBottom;
            $item->designedExtTop = $item->baseTop + $minOffset;
            $item->designedExtBottom = max(
                $item->baseTop + $item->baseHeight,
                $attachedBottom === null ? -INF : $item->baseTop + $attachedBottom,
            );
        }

        usort($items, static fn (DesignedFlowItem $a, DesignedFlowItem $b): int => $a->designedExtTop <=> $b->designedExtTop);

        $gaps = [];
        for ($i = 1, $n = count($items); $i < $n; $i++) {
            $gaps[] = $items[$i]->designedExtTop - $items[$i - 1]->designedExtBottom;
        }

        $consumed[$def->id] = true;

        return new DesignedFlowNode(
            $def->id,
            $def->maxHeight,
            $def->gap,
            $def->spaceAfter,
            $items,
            $gaps,
            $items[0]->designedExtTop,
            max(array_map(static fn (DesignedFlowItem $item): float => $item->designedExtBottom, $items)),
            $extLeft,
            $extRight,
        );
    }

    private static function measure(DesignedFlowNode $node): void
    {
        $previousBottom = null;

        foreach ($node->items as $i => $item) {
            if ($item->child !== null) {
                self::measure($item->child);
                $item->hiddenNow = $item->child->hidden;
                $baseHeightNow = $item->child->height;
            } else {
                $item->hiddenNow = $item->hidden;
                $baseHeightNow = $item->baseHeight;
            }

            $height = max($item->baseTopOffset + $baseHeightNow, $item->attachedBottom ?? -INF);

            if ($item->hiddenNow) {
                continue;
            }

            $gapBefore = $node->gap ?? ($node->gaps[$i - 1] ?? 0.0);
            $previous = $node->items[$i - 1] ?? null;
            if ($previous !== null && $previous->child !== null && $previous->child->spaceAfter !== null) {
                $gapBefore = max($gapBefore, $previous->child->spaceAfter);
            }
            $top = $previousBottom === null ? $node->anchorTop : $previousBottom + $gapBefore;
            $previousBottom = $top + $height;
        }

        $node->contentBottom = $previousBottom ?? $node->anchorTop;
        $node->height = $node->contentBottom - $node->anchorTop;
        $node->hidden = array_all($node->items, static fn (DesignedFlowItem $item): bool => $item->hiddenNow);
    }
}

/**
 * @internal one container of {@see DesignedContainerFlow}
 */
final class DesignedFlowNode
{
    public float $contentBottom = 0.0;
    public float $height = 0.0;
    public bool $hidden = false;

    /**
     * @param list<DesignedFlowItem> $items
     * @param list<float> $gaps
     */
    public function __construct(
        public readonly string $id,
        public readonly float $maxHeight,
        public readonly null|float $gap,
        public readonly null|float $spaceAfter,
        public readonly array $items,
        public readonly array $gaps,
        public readonly float $anchorTop,
        public readonly float $designedExtBottom,
        public readonly float $extLeft,
        public readonly float $extRight,
    ) {
    }

    public float $designedExtTop {
        get => $this->anchorTop;
    }
}

/**
 * @internal one flow item of {@see DesignedContainerFlow}
 */
final class DesignedFlowItem
{
    /** @var list<array{offset: float, height: float}> */
    public array $attachments = [];
    public float $baseTopOffset = 0.0;
    public null|float $attachedBottom = null;
    public float $designedExtTop = 0.0;
    public float $designedExtBottom = 0.0;
    public bool $hiddenNow = false;

    public function __construct(
        public readonly float $baseTop,
        public readonly float $baseHeight,
        public readonly bool $hidden,
        public readonly null|DesignedFlowNode $child = null,
    ) {
    }
}
