<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A {@see CanvasContainer} definition RESOLVED against one variant's designed
 * geometry — the shape every consumer-facing surface reports a container as.
 *
 * The definition alone cannot be published: it names members but says nothing
 * about where the container sits, and it may name members that are not fillable
 * (design-hidden texts, decorative images) or children that resolve to nothing.
 * {@see self::collection()} is the single place that turns a definition list
 * plus the variant's text frames into the published view, so the REST listing
 * (`variants[].containers[]`) and the MCP `describe_variant` tool cannot report
 * a different zone for the same design.
 *
 * `y` is the anchor: the highest designed member frame in the container's TREE
 * (its own direct members plus, for a nesting parent, its children's anchors) —
 * the coordinate a consumer draws the zone from. `maxHeight` bounds the flow
 * from there downward, and only for a container that is not itself
 * {@see $nested} (a child grows freely with its content).
 *
 * A HIDABLE container whose tree holds no fillable text (only decorative
 * images / shapes) is published too, so a consumer can offer its hide toggle:
 * anchored at its highest decorative member, with empty `memberInputIds`, and
 * deliberately NOT added to its parent's `memberContainerIds` — the parent's
 * published reflow tree stays exactly what it was before (2026-11-09).
 */
readonly final class ResolvedCanvasContainer
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
        /** The designer opted the WHOLE container into hiding (see {@see CanvasContainer::$hidable}). */
        public bool $hidable = false,
        /** Designer label — the hide toggle's caption. */
        public null|string $name = null,
    ) {
    }

    /**
     * Resolve every publishable container of one variant.
     *
     * A container whose whole tree resolves to no locatable member is OMITTED
     * (it cannot reflow anything at render time), and a dropped container also
     * disappears from its parent's `memberContainerIds` — a consumer must never
     * be handed a child reference it cannot look up.
     *
     * Member ids are narrowed to the LISTED inputs: a design-hidden member (the
     * editor's per-layer eye toggle) is not fillable, is absent from `inputs[]`,
     * and the render-time layout skips it exactly like a deleted member — a
     * consumer mirroring the reflow must not see it either. Decorative image
     * members ride the flow server-side only and are likewise not listed (they
     * are not fillable).
     *
     * @param list<CanvasContainer> $containers
     * @param array<string, PlaceholderFrame> $frames text-input frames, keyed by inputId
     * @param array<EditorTextInput> $inputs the variant's listed text inputs
     * @param array<string, float> $decorationTops designed top of every
     *   decorative member (inputId → y), the anchor of a hidable
     *   decoration-only container; empty = such containers stay omitted
     *
     * @return list<self>
     */
    public static function collection(array $containers, array $frames, array $inputs, array $decorationTops = []): array
    {
        $inputIds = [];
        foreach ($inputs as $input) {
            $inputIds[$input->inputId] = true;
        }

        $byId = [];
        foreach ($containers as $container) {
            $byId[$container->id] = $container;
        }

        /** @var array<string, null|float> $anchors */
        $anchors = [];
        $resolveAnchor = function (CanvasContainer $container) use (&$resolveAnchor, &$anchors, $byId, $frames): null|float {
            if (array_key_exists($container->id, $anchors)) {
                return $anchors[$container->id];
            }
            $anchors[$container->id] = null; // cycle guard

            $candidates = [];
            foreach ($container->memberInputIds as $memberInputId) {
                if (isset($frames[$memberInputId])) {
                    $candidates[] = $frames[$memberInputId]->y;
                }
            }
            foreach ($container->memberContainerIds as $childId) {
                $child = $byId[$childId] ?? null;
                if ($child === null) {
                    continue;
                }
                $childAnchor = $resolveAnchor($child);
                if ($childAnchor !== null) {
                    $candidates[] = $childAnchor;
                }
            }

            return $anchors[$container->id] = ($candidates === [] ? null : min($candidates));
        };

        $resolvable = [];
        foreach ($containers as $container) {
            if ($resolveAnchor($container) !== null) {
                $resolvable[$container->id] = true;
            }
        }

        // Hidable containers without any fillable text: anchored at their
        // highest decorative member (deep), published for the toggle only.
        $deepDecorationTop = function (CanvasContainer $container, array $seen) use (&$deepDecorationTop, $byId, $decorationTops): null|float {
            if (isset($seen[$container->id])) {
                return null;
            }
            $seen[$container->id] = true;
            $candidates = [];
            foreach ($container->memberInputIds as $memberInputId) {
                if (isset($decorationTops[$memberInputId])) {
                    $candidates[] = $decorationTops[$memberInputId];
                }
            }
            foreach ($container->memberContainerIds as $childId) {
                $child = $byId[$childId] ?? null;
                $childTop = $child === null ? null : $deepDecorationTop($child, $seen);
                if ($childTop !== null) {
                    $candidates[] = $childTop;
                }
            }

            return $candidates === [] ? null : min($candidates);
        };

        $result = [];
        foreach ($containers as $container) {
            $y = $anchors[$container->id] ?? null;
            if ($y === null && $container->hidable && $decorationTops !== []) {
                $y = $deepDecorationTop($container, []);
            }
            if ($y === null) {
                continue;
            }

            $memberInputIds = array_values(array_filter(
                $container->memberInputIds,
                static fn (string $id): bool => isset($inputIds[$id]),
            ));
            $memberContainerIds = array_values(array_filter(
                $container->memberContainerIds,
                static fn (string $id): bool => isset($resolvable[$id]),
            ));

            $result[] = new self(
                id: $container->id,
                maxHeight: $container->maxHeight,
                y: $y,
                memberInputIds: $memberInputIds,
                memberContainerIds: $memberContainerIds,
                gap: $container->gap,
                spaceAfter: $container->spaceAfter,
                nested: $container->isNestedIn($containers),
                hidable: $container->hidable,
                name: $container->name,
            );
        }

        return $result;
    }
}
