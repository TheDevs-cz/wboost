<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A "smart text area": a designer-authored group of members that reflow
 * vertically at render time — filled text that wraps to more lines pushes the
 * flow items below it down (hidden items collapse), bounded by
 * {@see self::$maxHeight}. Exceeding the bound is a validation error on the
 * strict render paths (API export → 400).
 *
 * Members are text placeholders and decorative images ({@see $memberInputIds})
 * plus, since the nesting rework, other containers ({@see $memberContainerIds}):
 * a child container is laid out first and flows in its parent as ONE item —
 * only the outermost (root) container's maxHeight bounds the flow, a nested
 * container grows freely with its content. {@see $gap} optionally replaces the
 * designed inter-item gaps with a uniform spacing.
 *
 * Containers are persisted as a top-level `containers` key INSIDE the canvas
 * JSONB document (not a separate column): the canvas string already travels
 * untouched through the whole save pipeline, the copy handlers, and every
 * consumer that needs container data (renderer, fill page, API providers)
 * already decodes the canvas. The layout algorithm itself lives in
 * assets/editor/container_layout.js — the single JS source of truth shared by
 * the headless render, the admin editor and the fill page; this VO only
 * carries the definition.
 */
readonly final class CanvasContainer
{
    public function __construct(
        public string $id,
        /**
         * Maximum content height in canvas px, measured from the container's
         * top (= designed top of the first flow item) downward. Only binding
         * for a container that is NOT nested inside another one.
         */
        public float $maxHeight,
        /**
         * Member input UUIDs (texts + decorative images) in flow order (top
         * to bottom). The editor re-derives the order from the members'
         * vertical positions on every save.
         *
         * @var list<string>
         */
        public array $memberInputIds,
        /**
         * Child container ids nested inside this one (each flows as one item).
         *
         * @var list<string>
         */
        public array $memberContainerIds = [],
        /**
         * Uniform inter-item spacing in canvas px; null = the designed gaps
         * between the members are preserved (pre-nesting behavior).
         */
        public null|float $gap = null,
        /**
         * Guaranteed minimum clearance BELOW the container in canvas px:
         * the landing distance when it pushes a sibling, the floor of the
         * following gap when nested, and its page-bottom margin. Null = 0.
         */
        public null|float $spaceAfter = null,
        /**
         * Opt-in WHOLE-container hide (2026-11): the fill pages offer a toggle
         * and the API accepts `inputs[<containerId>] = {hide: true}`. Hiding
         * hides the container and every member of its tree (texts, decorative
         * images, shapes, nested containers) — at render time it expands into
         * the members' own hide overrides ({@see self::hiddenMemberInputIds()}),
         * so the layout treats it exactly like hidden members (collapse).
         */
        public bool $hidable = false,
        /**
         * Designer label of the container — the fill pages' toggle caption and
         * the API's `containers[].name`. Null = unnamed.
         */
        public null|string $name = null,
        /**
         * "Uživatel může posunout celý kontejner" — the end user may move the
         * whole block (only meaningful on a TOP-LEVEL container; inert when
         * nested). See {@see ElementMoveAllowance}.
         */
        public null|ElementMoveAllowance $userMove = null,
        /**
         * "Opakovatelný řádek" (container-rows plan P2): this NESTED container
         * is a row the end user may repeat. Null on every other container —
         * and dropped by {@see self::collectionFromCanvas()} wherever the
         * structural rules are broken (on a root, a second repeatable child
         * of the same parent, inside another repeatable tree).
         */
        public null|ContainerRepeat $repeat = null,
        /**
         * EXPANDED variants only ({@see \WBoost\Web\Services\Template\RepeatableRows}):
         * the container ids of every materialised slot of this row, slot 0
         * (= this container, original ids) first, `max` entries. Empty on a
         * stored design — its presence is what makes expansion idempotent.
         *
         * @var list<string>
         */
        public array $repeatSlotIds = [],
        /**
         * EXPANDED variants only: on a materialised slot k ≥ 1 of a row, the
         * row (template) container's id. Lets a single-member slot survive
         * the degenerate-container rule exactly like its template row.
         */
        public null|string $repeatSlotOf = null,
    ) {
    }

    public function isRepeatable(): bool
    {
        return $this->repeat !== null;
    }

    /**
     * `hidable` / `name` are emitted only when set, so every pre-existing
     * definition (and every payload built from one) keeps its exact shape.
     * `userMove` follows the same rule (M2): only a container the designer
     * let the user move carries it — the fill pages' move UI reads it.
     *
     * `repeat` / `repeatSlots` / `repeatSlotOf` follow the same rule — only a
     * repeatable row (and, on an expanded variant, its slots) carries them.
     *
     * @return array{id: string, maxHeight: float, memberInputIds: list<string>, memberContainerIds: list<string>, gap: null|float, spaceAfter: null|float, hidable?: true, name?: string, userMove?: array{vertical: bool, horizontal: bool, maxUp: null|float, maxDown: null|float, maxLeft: null|float, maxRight: null|float}, repeat?: array{max: int, min: int, default: int}, repeatSlots?: list<string>, repeatSlotOf?: string}
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'maxHeight' => $this->maxHeight,
            'memberInputIds' => $this->memberInputIds,
            'memberContainerIds' => $this->memberContainerIds,
            'gap' => $this->gap,
            'spaceAfter' => $this->spaceAfter,
        ];

        if ($this->hidable) {
            $data['hidable'] = true;
        }

        if ($this->name !== null) {
            $data['name'] = $this->name;
        }

        if ($this->userMove !== null) {
            $data['userMove'] = $this->userMove->toArray();
        }

        if ($this->repeat !== null) {
            $data['repeat'] = $this->repeat->toArray();
        }

        if ($this->repeatSlotIds !== []) {
            $data['repeatSlots'] = $this->repeatSlotIds;
        }

        if ($this->repeatSlotOf !== null) {
            $data['repeatSlotOf'] = $this->repeatSlotOf;
        }

        return $data;
    }

    /**
     * Defensive: entries that cannot reflow anything (missing id, non-positive
     * max height, fewer than 2 members counting nested containers) yield null
     * and are dropped by {@see self::collectionFromCanvas} — an inert
     * container must never make a render misbehave. Invalid `gap` values fall
     * back to designed gaps; child references are sanity-filtered here only
     * lightly (strings) — cycle/one-parent enforcement happens at save time in
     * the editor and defensively in the shared layout engine.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): null|self
    {
        $id = $data['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }

        $maxHeight = $data['maxHeight'] ?? null;
        if (!is_int($maxHeight) && !is_float($maxHeight)) {
            return null;
        }
        $maxHeight = (float) $maxHeight;
        if ($maxHeight <= 0.0) {
            return null;
        }

        $memberInputIds = [];
        $rawMembers = $data['memberInputIds'] ?? null;
        if (is_array($rawMembers)) {
            foreach ($rawMembers as $memberId) {
                if (is_string($memberId) && $memberId !== '') {
                    $memberInputIds[] = $memberId;
                }
            }
        }

        $memberContainerIds = [];
        $rawChildren = $data['memberContainerIds'] ?? null;
        if (is_array($rawChildren)) {
            foreach ($rawChildren as $childId) {
                if (is_string($childId) && $childId !== '' && $childId !== $id) {
                    $memberContainerIds[] = $childId;
                }
            }
        }

        $repeat = ContainerRepeat::fromArray($data['repeat'] ?? null);
        $repeatSlotOf = is_string($data['repeatSlotOf'] ?? null) && $data['repeatSlotOf'] !== '' ? $data['repeatSlotOf'] : null;

        $repeatSlotIds = [];
        if ($repeat !== null && is_array($data['repeatSlots'] ?? null)) {
            foreach ($data['repeatSlots'] as $slotId) {
                if (is_string($slotId) && $slotId !== '') {
                    $repeatSlotIds[] = $slotId;
                }
            }
        }

        // A repeatable row (and each of its materialised slots) may be a
        // SINGLE text — the Výsledek scorer columns — and a parent may hold
        // NOTHING but its repeatable row (a list of results). Every other
        // container needs two members to reflow anything; the one-child
        // case is settled per collection ({@see self::withValidRepeats()}).
        $minimumMembers = ($repeat !== null || $repeatSlotOf !== null || $memberContainerIds !== []) ? 1 : 2;
        if (count($memberInputIds) + count($memberContainerIds) < $minimumMembers) {
            return null;
        }

        return new self(
            $id,
            $maxHeight,
            $memberInputIds,
            $memberContainerIds,
            self::nonNegativeFloatOrNull($data['gap'] ?? null),
            self::nonNegativeFloatOrNull($data['spaceAfter'] ?? null),
            ($data['hidable'] ?? false) === true,
            self::nameOrNull($data['name'] ?? null),
            ElementMoveAllowance::fromCanvasObject($data),
            $repeat,
            $repeatSlotIds,
            $repeatSlotOf,
        );
    }

    private static function nameOrNull(mixed $value): null|string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function nonNegativeFloatOrNull(mixed $value): null|float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $value = (float) $value;

        return ($value < 0.0 || !is_finite($value)) ? null : $value;
    }

    /**
     * Extract the container definitions from a decoded canvas document.
     *
     * @param array<array-key, mixed> $canvas
     * @return list<self>
     */
    public static function collectionFromCanvas(array $canvas): array
    {
        $rawContainers = $canvas['containers'] ?? null;
        if (!is_array($rawContainers)) {
            return [];
        }

        $collection = [];
        foreach ($rawContainers as $rawContainer) {
            if (!is_array($rawContainer)) {
                continue;
            }
            $container = self::fromArray($rawContainer);
            if ($container !== null) {
                $collection[] = $container;
            }
        }

        return self::withValidRepeats($collection);
    }

    /**
     * The structural repeat rules (plan P2), enforced on the collection:
     * a repeatable row must be NESTED (its parent is the flow and the bound),
     * at most one per parent (the first in the parent's member order wins),
     * and never inside another repeatable tree (v1). A container that breaks
     * a rule loses `repeat` — and is dropped entirely when it then has fewer
     * than two members (the single-text exception was repeat-only). A
     * container whose ONLY member is a child container survives only when
     * that child is a valid repeatable row.
     *
     * @param list<self> $collection
     * @return list<self>
     */
    private static function withValidRepeats(array $collection): array
    {
        $hasRepeat = false;
        foreach ($collection as $container) {
            if ($container->repeat !== null) {
                $hasRepeat = true;
                break;
            }
        }

        if (!$hasRepeat) {
            // No rows anywhere: the pre-rows rule, 2+ members (a lone child
            // container does not count).
            return array_values(array_filter(
                $collection,
                static fn (self $container): bool => $container->repeatSlotOf !== null
                    || count($container->memberInputIds) + count($container->memberContainerIds) >= 2,
            ));
        }

        $byId = [];
        $parentOf = [];
        foreach ($collection as $container) {
            $byId[$container->id] ??= $container;
        }
        foreach ($collection as $container) {
            foreach ($container->memberContainerIds as $childId) {
                $parentOf[$childId] ??= $container->id;
            }
        }

        $valid = [];
        $claimedParents = [];
        foreach ($collection as $container) {
            foreach ($container->memberContainerIds as $childId) {
                $child = $byId[$childId] ?? null;
                if ($child === null || $child->repeat === null || ($parentOf[$childId] ?? null) !== $container->id) {
                    continue;
                }
                if (isset($claimedParents[$container->id])) {
                    continue;
                }

                // Inside another repeatable tree (any repeatable ancestor)?
                $ancestor = $container->id;
                $seen = [];
                $nestedInRepeat = false;
                while ($ancestor !== null && !isset($seen[$ancestor])) {
                    $seen[$ancestor] = true;
                    if (($byId[$ancestor] ?? null)?->repeat !== null) {
                        $nestedInRepeat = true;
                        break;
                    }
                    $ancestor = $parentOf[$ancestor] ?? null;
                }
                if ($nestedInRepeat) {
                    continue;
                }

                $claimedParents[$container->id] = true;
                $valid[$childId] = true;
            }
        }

        $result = [];
        foreach ($collection as $container) {
            $memberCount = count($container->memberInputIds) + count($container->memberContainerIds);

            if ($container->repeat === null && $container->repeatSlotOf === null && $memberCount < 2) {
                // A lone member is a flow only when it is a repeatable row.
                $holdsRow = array_any($container->memberContainerIds, static fn (string $childId): bool => isset($valid[$childId]));
                if ($holdsRow) {
                    $result[] = $container;
                }
                continue;
            }

            if ($container->repeat === null || isset($valid[$container->id])) {
                $result[] = $container;
                continue;
            }

            if (count($container->memberInputIds) + count($container->memberContainerIds) < 2) {
                continue;
            }

            $result[] = new self(
                $container->id,
                $container->maxHeight,
                $container->memberInputIds,
                $container->memberContainerIds,
                $container->gap,
                $container->spaceAfter,
                $container->hidable,
                $container->name,
                $container->userMove,
            );
        }

        return $result;
    }

    /**
     * Is this container nested inside another one of the given collection?
     * (Nested containers grow freely — only roots bound the flow.)
     *
     * @param list<self> $containers
     */
    public function isNestedIn(array $containers): bool
    {
        foreach ($containers as $candidate) {
            if ($candidate->id !== $this->id && in_array($this->id, $candidate->memberContainerIds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every member inputId of this container's TREE — its own members plus,
     * recursively, those of its nested containers (cycle-safe). Order: own
     * members first, then each child's tree.
     *
     * @param list<self> $containers the whole collection (child lookups)
     * @return list<string>
     */
    public function deepMemberInputIds(array $containers): array
    {
        $byId = [];
        foreach ($containers as $container) {
            $byId[$container->id] ??= $container;
        }

        $result = [];
        $seen = [];
        $walk = static function (self $container) use (&$walk, &$result, &$seen, $byId): void {
            if (isset($seen[$container->id])) {
                return;
            }
            $seen[$container->id] = true;

            foreach ($container->memberInputIds as $inputId) {
                $result[$inputId] = true;
            }

            foreach ($container->memberContainerIds as $childId) {
                $child = $byId[$childId] ?? null;
                if ($child !== null) {
                    $walk($child);
                }
            }
        };
        $walk($this);

        return array_map(strval(...), array_keys($result));
    }

    /**
     * The container-hide expansion — the ONE place a hidden container turns
     * into hidden objects: the deep member inputIds of every HIDABLE
     * container named in `$hiddenContainerIds`. Ids that name no container,
     * or a container the designer did not mark hidable, contribute nothing
     * (lenient — the strict API path refuses those before it gets here).
     *
     * The render applies the result as ordinary per-object hide overrides,
     * which is what makes a hidden container behave exactly like hidden
     * members in the shared layout engine: its items collapse, a nested
     * container whose items are all hidden collapses in its parent, and a root
     * whose items are all hidden takes no flow space. The members' OWN hide
     * states are untouched, so showing the container again restores them.
     *
     * @param list<self> $containers
     * @param iterable<string> $hiddenContainerIds
     * @return list<string>
     */
    public static function hiddenMemberInputIds(array $containers, iterable $hiddenContainerIds): array
    {
        $byId = [];
        foreach ($containers as $container) {
            $byId[$container->id] ??= $container;
        }

        $result = [];
        foreach ($hiddenContainerIds as $containerId) {
            $container = $byId[$containerId] ?? null;
            if ($container === null || !$container->hidable) {
                continue;
            }

            foreach ($container->deepMemberInputIds($containers) as $inputId) {
                $result[$inputId] = true;
            }
        }

        return array_map(strval(...), array_keys($result));
    }

    /**
     * The repeatable-rows hide expansion (container-rows P2): the deep member
     * ids of every materialised slot k ≥ count of every repeatable row of an
     * EXPANDED collection (count = `$rowCounts[rowId]` clamped to the row's
     * `[min, max]`, its default when absent). See
     * {@see \WBoost\Web\Services\Template\RepeatableRows}.
     *
     * @param list<self> $containers
     * @param array<string, int> $rowCounts
     * @return list<string>
     */
    public static function inactiveRowMemberIds(array $containers, array $rowCounts): array
    {
        $byId = [];
        foreach ($containers as $container) {
            $byId[$container->id] ??= $container;
        }

        $hidden = [];
        foreach ($containers as $container) {
            if ($container->repeat === null || $container->repeatSlotIds === []) {
                continue;
            }

            $count = $container->repeat->clamp($rowCounts[$container->id] ?? null);
            foreach (array_slice($container->repeatSlotIds, $count) as $slotContainerId) {
                $slot = $byId[$slotContainerId] ?? null;
                if ($slot === null) {
                    continue;
                }
                foreach ($slot->deepMemberInputIds($containers) as $memberId) {
                    $hidden[$memberId] = true;
                }
            }
        }

        return array_map(strval(...), array_keys($hidden));
    }

    /**
     * Hidable container id → its deep member inputIds — what the fill pages'
     * client-side mirrors (overlay reflow, text echo) need to hide a
     * container's tree the way the render does, without re-deriving the
     * expansion in JS.
     *
     * @param list<self> $containers
     * @return array<string, list<string>>
     */
    public static function hidableMemberMap(array $containers): array
    {
        $map = [];
        foreach ($containers as $container) {
            if ($container->hidable) {
                $map[$container->id] = $container->deepMemberInputIds($containers);
            }
        }

        return $map;
    }

    /**
     * @param list<self> $containers
     */
    public static function findById(array $containers, string $id): null|self
    {
        foreach ($containers as $container) {
            if ($container->id === $id) {
                return $container;
            }
        }

        return null;
    }
}
