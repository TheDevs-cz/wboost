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
 */
final readonly class ContainerRepeat
{
    /** Hard cap: expansion materialises `max` copies of the row. */
    public const int MAX_ROWS = 30;

    private function __construct(
        public int $min,
        public int $max,
        public int $default,
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

        return new self($min, $max, $default);
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
     * @return array{max: int, min: int, default: int}
     */
    public function toArray(): array
    {
        return ['max' => $this->max, 'min' => $this->min, 'default' => $this->default];
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
