<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

/**
 * The CUMULATIVE usage of one `(scan, run, step)` as the scanner last
 * reported it. Everything is clamped: the numbers come from another process.
 */
final readonly class AiUsageFigures
{
    public function __construct(
        public string $provider = 'anthropic',
        public string $model = '',
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        /** Every cache write, whatever its lifetime. */
        public int $cacheWriteTokens = 0,
        /** The part of {@see $cacheWriteTokens} written with the 1-hour lifetime (priced 2× input, the rest 1.25×). */
        public int $cacheWrite1hTokens = 0,
        public int $engineCostMicroUsd = 0,
        public int $turns = 0,
        public int $durationMs = 0,
    ) {
    }

    public static function fromPayload(mixed $raw): self
    {
        if (!is_array($raw)) {
            return new self();
        }

        // The scanner sends the total and its split by lifetime
        // (`cacheWrite5mTokens` + `cacheWrite1hTokens`). The total is what an
        // older scanner sends alone: it is then all 5-minute.
        $oneHour = self::count($raw['cacheWrite1hTokens'] ?? null);
        $total = max(
            self::count($raw['cacheWriteTokens'] ?? null),
            min(2_000_000_000, $oneHour + self::count($raw['cacheWrite5mTokens'] ?? null)),
        );

        return new self(
            provider: self::code($raw['provider'] ?? null, 'anthropic'),
            model: self::code($raw['model'] ?? null, ''),
            inputTokens: self::count($raw['inputTokens'] ?? null),
            outputTokens: self::count($raw['outputTokens'] ?? null),
            cacheReadTokens: self::count($raw['cacheReadTokens'] ?? null),
            cacheWriteTokens: $total,
            cacheWrite1hTokens: min($oneHour, $total),
            engineCostMicroUsd: self::count($raw['engineCostMicroUsd'] ?? null),
            turns: self::count($raw['turns'] ?? null, 100000),
            durationMs: self::count($raw['durationMs'] ?? null),
        );
    }

    private static function count(mixed $value, int $max = 2_000_000_000): int
    {
        if (is_float($value)) {
            $value = (int) round($value);
        }

        return is_int($value) ? max(0, min($value, $max)) : 0;
    }

    private static function code(mixed $value, string $default): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9._:\-]{1,64}$/', $value) !== 1) {
            return $default;
        }

        return $value;
    }
}
