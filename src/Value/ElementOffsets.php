<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * Bakes user-chosen element offsets ("Uživatel může posunout",
 * {@see ElementMoveAllowance}) into a decoded canvas document: the moved
 * objects simply get a different `left` / `top` — the same "bake into the
 * canvas JSON, the render template needs no new code" pattern as shape colours
 * and image placements.
 *
 * Pure translation, no validation: the caller (the M2 resolver) has already
 * clamped every offset and dropped the ineligible ones. Keys are either an
 * object's `inputId` or a `containers[]` definition `id`; a container moves
 * its whole deep tree (members of nested containers included). When several
 * keys reach the same object their offsets ADD — the resolver keeps the sets
 * disjoint (a container member never carries its own offset).
 *
 * Only `left` / `top` (and the absolutely positioned `clipPath` of a framed
 * image slot, which must ride along so the picture stays in its frame) are
 * written: object order, visibility and every input binding are untouched, so
 * the positional textbox ↔ input contract cannot break. Called BEFORE the
 * container layout's phase A, a moved root therefore takes part in sibling
 * collision-push and page-bottom overflow like any designed position.
 *
 * JS twin: `WBoostElementMove.bake` in `assets/editor/element_move.js`
 * (parity-pinned by `ElementMoveParityTest`).
 */
final class ElementOffsets
{
    /**
     * @param array<array-key, mixed> $canvas decoded canvas document
     * @param array<string, array{x: float, y: float}> $offsets element id → offset in canvas px
     *
     * @return array<array-key, mixed>
     */
    public static function bake(array $canvas, array $offsets): array
    {
        $deltas = self::objectDeltas($canvas, $offsets);

        if ($deltas === [] || !isset($canvas['objects']) || !is_array($canvas['objects'])) {
            return $canvas;
        }

        foreach ($canvas['objects'] as $index => $object) {
            if (!is_array($object)) {
                continue;
            }

            $inputId = $object['inputId'] ?? null;
            if (!is_string($inputId) || !isset($deltas[$inputId])) {
                continue;
            }

            [$dx, $dy] = $deltas[$inputId];
            $object['left'] = self::number($object['left'] ?? 0) + $dx;
            $object['top'] = self::number($object['top'] ?? 0) + $dy;

            if (isset($object['clipPath']) && is_array($object['clipPath']) && ($object['clipPath']['absolutePositioned'] ?? false) === true) {
                $object['clipPath']['left'] = self::number($object['clipPath']['left'] ?? 0) + $dx;
                $object['clipPath']['top'] = self::number($object['clipPath']['top'] ?? 0) + $dy;
            }

            $canvas['objects'][$index] = $object;
        }

        return $canvas;
    }

    /**
     * inputId → summed [dx, dy] (zero totals omitted).
     *
     * @param array<array-key, mixed> $canvas
     * @param array<string, array{x: float, y: float}> $offsets
     *
     * @return array<string, array{0: float, 1: float}>
     */
    private static function objectDeltas(array $canvas, array $offsets): array
    {
        /** @var array<string, array<array-key, mixed>> $containers */
        $containers = [];
        foreach (is_array($canvas['containers'] ?? null) ? $canvas['containers'] : [] as $container) {
            if (is_array($container) && is_string($container['id'] ?? null) && $container['id'] !== '') {
                $containers[$container['id']] ??= $container;
            }
        }

        $deltas = [];
        $add = static function (string $inputId, float $dx, float $dy) use (&$deltas): void {
            $current = $deltas[$inputId] ?? [0.0, 0.0];
            $deltas[$inputId] = [$current[0] + $dx, $current[1] + $dy];
        };

        foreach ($offsets as $id => $offset) {
            $dx = self::number($offset['x']);
            $dy = self::number($offset['y']);
            if ($dx == 0.0 && $dy == 0.0) {
                continue;
            }

            if (isset($containers[$id])) {
                foreach (self::deepMemberInputIds($containers, (string) $id) as $memberId) {
                    $add($memberId, $dx, $dy);
                }

                continue;
            }

            $add((string) $id, $dx, $dy);
        }

        return array_filter($deltas, static fn (array $delta): bool => $delta[0] != 0.0 || $delta[1] != 0.0);
    }

    /**
     * Member inputIds of a container and of every container nested in it
     * (cycle-safe, each id once, flow order of first appearance).
     *
     * @param array<string, array<array-key, mixed>> $containers
     *
     * @return list<string>
     */
    private static function deepMemberInputIds(array $containers, string $rootId): array
    {
        $result = [];
        $seenIds = [];
        $visited = [];
        $stack = [$rootId];

        while ($stack !== []) {
            $id = array_shift($stack);
            if (isset($visited[$id]) || !isset($containers[$id])) {
                continue;
            }
            $visited[$id] = true;
            $container = $containers[$id];

            foreach (is_array($container['memberInputIds'] ?? null) ? $container['memberInputIds'] : [] as $memberId) {
                if (is_string($memberId) && $memberId !== '' && !isset($seenIds[$memberId])) {
                    $seenIds[$memberId] = true;
                    $result[] = $memberId;
                }
            }

            foreach (is_array($container['memberContainerIds'] ?? null) ? $container['memberContainerIds'] : [] as $childId) {
                if (is_string($childId)) {
                    $stack[] = $childId;
                }
            }
        }

        return $result;
    }

    private static function number(mixed $value): float
    {
        if (!is_int($value) && !is_float($value)) {
            return 0.0;
        }

        return is_finite((float) $value) ? (float) $value : 0.0;
    }
}
