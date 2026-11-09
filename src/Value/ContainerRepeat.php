<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * "Opakovatelný řádek" — a NESTED container the end user may repeat
 * (container-rows plan, P2): the designer builds ONE row (e.g. "Skóre" +
 * "Soupeř") and the fill pages let the user show between {@see $min} and
 * {@see $max} copies of it, {@see $default} on a fresh fill.
 *
 * Stored on the row's definition in `canvas.containers[]` as
 * `"repeat": {"max": 8, "min": 1, "default": 1}` — emitted only when set, so
 * every other container keeps its exact JSON. The structural rules (nested
 * only, one repeatable child per parent, never inside another repeatable
 * tree) need the whole collection and live in
 * {@see CanvasContainer::collectionFromCanvas()}; this VO validates the
 * numbers alone.
 *
 * The server materialises `max` slots on a clone of the variant and hides the
 * unused ones ({@see \WBoost\Web\Services\Template\RepeatableRows}).
 *
 * **Legacy slot ids** (`slots`, container-rows P6 — "Sloučit do opakovaného
 * řádku"): when a designer MERGES hand-numbered copies of a row into one
 * repeatable row, the copies' ids live on in stored export versions, drafts,
 * API callers and documents. `slots[k-1]` maps an id of the template row's
 * tree (member inputIds AND container ids) to the id that row k+1 HAD:
 * `"slots": [{"<skóre1>": "<skóre2>", "<souper1>": "<souper2>"}, …]`.
 * Expansion then gives slot k those legacy ids instead of the derived uuid5
 * ({@see \WBoost\Web\Services\Template\RepeatableRows::expandCanvas()}),
 * so every old value lands in its row unchanged. Absent for a row authored
 * as repeatable from the start; an entry may be shorter than the tree (the
 * rest falls back to the derived id).
 */
final readonly class ContainerRepeat
{
    /** Hard cap: expansion materialises `max` copies of the row. */
    public const int MAX_ROWS = 30;

    /**
     * @param list<array<string, string>> $slots legacy ids per slot k ≥ 1
     *   (index k−1): template-tree id → the id that row had before the merge
     */
    private function __construct(
        public int $min,
        public int $max,
        public int $default,
        public array $slots = [],
    ) {
    }

    /**
     * Defensive parse: `max` is required; `min` defaults to 1 and `default`
     * to `min`. Anything outside `1 ≤ min ≤ default ≤ max ≤ 30` (or not an
     * integer) yields null — an inert definition is treated as not
     * repeatable rather than guessed at.
     */
    public static function fromArray(mixed $data): null|self
    {
        if (!is_array($data)) {
            return null;
        }

        $max = self::intOrNull($data['max'] ?? null);
        $min = array_key_exists('min', $data) ? self::intOrNull($data['min']) : 1;

        if ($max === null || $min === null) {
            return null;
        }

        $default = array_key_exists('default', $data) ? self::intOrNull($data['default']) : $min;

        if ($default === null || $min < 1 || $max > self::MAX_ROWS || $min > $default || $default > $max) {
            return null;
        }

        return new self($min, $max, $default, self::slotsFrom($data['slots'] ?? null));
    }

    /**
     * The legacy id row `$slot` (≥ 1) uses for the template-tree id
     * `$templateId`, or null when the merge left none (→ the derived id).
     */
    public function legacyId(int $slot, string $templateId): null|string
    {
        return $slot >= 1 ? ($this->slots[$slot - 1][$templateId] ?? null) : null;
    }

    /**
     * The row count a fill actually renders: the requested count clamped to
     * `[min, max]`; null (no request) = the designer's default.
     */
    public function clamp(null|int $count): int
    {
        if ($count === null) {
            return $this->default;
        }

        return max($this->min, min($this->max, $count));
    }

    /**
     * The LIMITS — what every reader outside the stored design needs (fill
     * layout data, describe_variant, the API). The legacy slot ids are a
     * design-storage detail: see {@see toStoredArray()}.
     *
     * @return array{max: int, min: int, default: int}
     */
    public function toArray(): array
    {
        return ['max' => $this->max, 'min' => $this->min, 'default' => $this->default];
    }

    /**
     * The definition as STORED in `canvas.containers[].repeat` — the limits
     * plus the legacy slot ids when a merge left any (emitted only then, so
     * every other row keeps its exact JSON). For writers of the design: the
     * MCP compiler and the decompiled DSL (which must round-trip them).
     *
     * @return array{max: int, min: int, default: int, slots?: list<array<string, string>>}
     */
    public function toStoredArray(): array
    {
        $data = $this->toArray();

        if ($this->slots !== []) {
            $data['slots'] = $this->slots;
        }

        return $data;
    }

    /**
     * Defensive parse of `slots`: a list (at most MAX_ROWS − 1 entries) of
     * string → string maps; malformed pairs are dropped, a malformed entry
     * becomes an empty map (its row falls back to derived ids) so the
     * positions of the later rows stay right. Trailing empty entries are
     * trimmed.
     *
     * @return list<array<string, string>>
     */
    private static function slotsFrom(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return [];
        }

        $slots = [];
        foreach (array_slice($raw, 0, self::MAX_ROWS - 1) as $entry) {
            $map = [];
            if (is_array($entry)) {
                foreach ($entry as $templateId => $legacyId) {
                    if (is_string($templateId) && $templateId !== '' && is_string($legacyId) && $legacyId !== '') {
                        $map[$templateId] = $legacyId;
                    }
                }
            }
            $slots[] = $map;
        }

        while ($slots !== [] && $slots[array_key_last($slots)] === []) {
            array_pop($slots);
        }

        return $slots;
    }

    private static function intOrNull(mixed $value): null|int
    {
        if (is_int($value)) {
            return $value;
        }

        // JSON numbers decoded from a stored canvas can be whole floats.
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }
}
