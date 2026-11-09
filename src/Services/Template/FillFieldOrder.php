<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Template;

/**
 * Applies a template's admin-arranged fill field order
 * ({@see \WBoost\Web\Entity\Template::$fillFieldOrder}) to the fields a fill
 * page lists. Arranged ids come first, in the stored order; fields the
 * arrangement does not know (added to the design afterwards) follow in their
 * natural order, and stored ids that no longer exist are simply ignored — so
 * a stale arrangement never hides or drops a field.
 *
 * Repeatable rows (container-rows P4): the fields of a row's materialised
 * slots stay TOGETHER, row by row — the whole row block sits where its
 * earliest field ranks, slot 0 first, and inside each slot the fields keep
 * the rank of their designed (slot 0) counterpart. Without the row index
 * nothing changes.
 */
readonly final class FillFieldOrder
{
    /**
     * @template T
     *
     * @param list<T> $fields in natural order
     * @param callable(T): string $inputIdOf
     * @param list<string> $order
     * @param array<string, array{row: string, role: string, slot: int}> $rowSlots
     *   {@see \WBoost\Web\Services\SocialNetwork\FillRepeatableRows::slotIndex()}
     *
     * @return list<T>
     */
    public function apply(array $fields, callable $inputIdOf, array $order, array $rowSlots = []): array
    {
        if ($order === [] && $rowSlots === []) {
            return $fields;
        }

        $rank = array_flip($order);
        $unranked = count($rank);

        $base = [];
        foreach ($fields as $index => $field) {
            $id = $inputIdOf($field);
            $base[$id] ??= $rank[$id] ?? $unranked + $index;
        }

        $rowPrimary = [];
        foreach ($base as $id => $value) {
            $slot = $rowSlots[$id] ?? null;
            if ($slot !== null) {
                $rowPrimary[$slot['row']] = min($rowPrimary[$slot['row']] ?? PHP_INT_MAX, $value);
            }
        }

        $keyed = [];
        foreach ($fields as $index => $field) {
            $id = $inputIdOf($field);
            $own = $rank[$id] ?? $unranked + $index;
            $slot = $rowSlots[$id] ?? null;
            $key = $slot === null
                ? [$own, 0, 0]
                : [$rowPrimary[$slot['row']], $slot['slot'], $base[$slot['role']] ?? $own];
            $keyed[] = [$key, $field];
        }

        usort($keyed, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(static fn (array $entry): mixed => $entry[1], $keyed);
    }
}
