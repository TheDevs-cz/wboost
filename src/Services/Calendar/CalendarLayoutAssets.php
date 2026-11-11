<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use WBoost\Web\Exceptions\InvalidCalendarChange;

/**
 * Finds and rewrites the asset references of a Layout document
 * (docs/calendar/architecture.md §C.5 step 3): the value of every key
 * `asset` or `icon`, and every string member of a LIST under the keys
 * `icons` or `assets` (`styles.moon.icons`, `styles.icons.season.assets`).
 * `styles.icons` itself is a map and is simply walked.
 */
final class CalendarLayoutAssets
{
    public const string ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param bool $strict false = references that are not asset ids are skipped
     *   instead of refused (a layout file from elsewhere)
     * @return list<string> lower-case asset ids, unique
     *
     * @throws InvalidCalendarChange when a reference is not an asset id (strict only)
     */
    public static function ids(\stdClass $layout, bool $strict = true): array
    {
        $ids = [];

        self::walk($layout, static function (string $id) use (&$ids, $strict): string {
            if (preg_match(self::ID_PATTERN, $id) !== 1) {
                if ($strict) {
                    throw new InvalidCalendarChange('Layout odkazuje na neplatný soubor.');
                }

                return $id;
            }

            $ids[strtolower($id)] = true;

            return $id;
        });

        return array_map(strval(...), array_keys($ids));
    }

    /**
     * A deep copy of the layout with every asset reference passed through
     * `$map` (lower-case old id → new id, or null to drop the reference);
     * ids missing from the map become null.
     *
     * @param array<string, null|string> $map
     */
    public static function rewrite(\stdClass $layout, array $map): \stdClass
    {
        /** @var \stdClass $copy */
        $copy = unserialize(serialize($layout));

        self::walk($copy, static fn (string $id): null|string => $map[strtolower($id)] ?? null);

        return $copy;
    }

    /**
     * @param callable(string): (null|string) $replace
     */
    private static function walk(\stdClass $node, callable $replace): void
    {
        foreach (get_object_vars($node) as $key => $value) {
            if (($key === 'asset' || $key === 'icon') && is_string($value)) {
                $node->{$key} = $replace($value);

                continue;
            }

            if (($key === 'icons' || $key === 'assets') && is_array($value)) {
                $node->{$key} = array_map(
                    static fn (mixed $member): mixed => is_string($member) ? $replace($member) : $member,
                    $value,
                );

                continue;
            }

            if ($value instanceof \stdClass) {
                self::walk($value, $replace);

                continue;
            }

            if (is_array($value)) {
                foreach ($value as $member) {
                    if ($member instanceof \stdClass) {
                        self::walk($member, $replace);
                    }
                }
            }
        }
    }
}
