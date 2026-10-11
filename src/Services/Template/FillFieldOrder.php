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
 */
readonly final class FillFieldOrder
{
    /**
     * @template T
     *
     * @param list<T> $fields in natural order
     * @param callable(T): string $inputIdOf
     * @param list<string> $order
     *
     * @return list<T>
     */
    public function apply(array $fields, callable $inputIdOf, array $order): array
    {
        if ($order === []) {
            return $fields;
        }

        $rank = array_flip($order);
        $unranked = count($rank);

        $keyed = [];
        foreach ($fields as $index => $field) {
            $keyed[] = [$rank[$inputIdOf($field)] ?? $unranked + $index, $field];
        }

        usort($keyed, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(static fn (array $entry): mixed => $entry[1], $keyed);
    }
}
