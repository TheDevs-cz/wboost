<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The scanner definition the app owns (`resources/ai-scan/definition/`): the
 * protocol version, caps, timings, the price table — and the prompt / schema
 * files the scanner receives in every claim answer, so the scanner image
 * holds no prompt and no schema.
 */
final class AiScanDefinition
{
    /**
     * The consent text version a user must have agreed to. The wording is a
     * draft until gate G1 (architecture.md §6); changing it means a new value
     * here, which asks everyone again.
     */
    private const string CONSENT_VERSION = '2026-11-draft';

    /** @var null|array<string, mixed> */
    private null|array $definition = null;

    /** @var null|array<string, string> */
    private null|array $files = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/ai-scan/definition')]
        private readonly string $directory,
    ) {
    }

    public function consentVersion(): string
    {
        return self::CONSENT_VERSION;
    }

    /** The protocol version N this app speaks; it also accepts N − 1. */
    public function protocolVersion(): int
    {
        return max(1, self::int($this->definition()['protocolVersion'] ?? null, 1));
    }

    public function acceptsProtocol(int $version): bool
    {
        $current = $this->protocolVersion();

        return $version >= 1 && ($version === $current || $version === $current - 1);
    }

    /**
     * @return list<string>
     */
    public function requiredInstruments(): array
    {
        $required = $this->definition()['requiresInstruments'] ?? [];

        return is_array($required) ? array_values(array_filter($required, is_string(...))) : [];
    }

    /**
     * The kinds a scanner offering these instruments can BUILD: a kind may
     * name instruments of its own (`kinds.<kind>.requiresInstruments`) on top
     * of the definition's. A scanner image older than a kind keeps serving
     * the others — the claim never hands it a scan of a kind it cannot build
     * (it would crash on it and the scan would fail as `interrupted`).
     *
     * @param list<string> $instruments
     * @return list<string>
     */
    public function buildableKinds(array $instruments): array
    {
        $kinds = $this->definition()['kinds'] ?? [];
        $buildable = [];

        foreach (is_array($kinds) ? $kinds : [] as $kind => $spec) {
            $required = is_array($spec) ? ($spec['requiresInstruments'] ?? []) : [];
            $required = is_array($required) ? array_filter($required, is_string(...)) : [];

            if (array_diff($required, $instruments) === []) {
                $buildable[] = (string) $kind;
            }
        }

        return $buildable;
    }

    /**
     * Can a scanner offering these instruments build this VARIANT of a kind
     * (`kinds.<kind>.variants.<variant>.requiresInstruments` — the 14-day
     * layout of a calendar scan)? The same rule as {@see buildableKinds()},
     * one level down: an image older than the variant keeps building the
     * kind's other scans and is never handed one it would fail on.
     *
     * @param list<string> $instruments
     */
    public function canBuildVariant(string $kind, string $variant, array $instruments): bool
    {
        $kinds = $this->definition()['kinds'] ?? null;
        $spec = is_array($kinds) && is_array($kinds[$kind] ?? null) ? $kinds[$kind] : [];
        $variants = is_array($spec['variants'] ?? null) ? $spec['variants'] : [];
        $required = is_array($variants[$variant] ?? null) ? ($variants[$variant]['requiresInstruments'] ?? []) : [];
        $required = is_array($required) ? array_filter($required, is_string(...)) : [];

        return array_diff($required, $instruments) === [];
    }

    /** What one running run reserves against the spend ceilings. */
    public function engineBudgetMicroUsd(): int
    {
        return self::int($this->cap('engineBudgetMicroUsd'), 1_500_000);
    }

    public function maxCandidates(): int
    {
        return max(1, self::int($this->cap('candidates'), 3));
    }

    public function priceVersion(): null|string
    {
        $prices = $this->definition()['prices'] ?? null;
        $version = is_array($prices) ? ($prices['version'] ?? null) : null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * Cost of a usage figure by the price table (USD per million tokens), or
     * null when the table does not know the model — the caller then keeps the
     * engine's own figure.
     *
     * `$cacheWrite` is every cache write; `$cacheWrite1h` the part of it
     * written with the 1-hour lifetime, priced by `cacheWrite1h` (2 × input —
     * the 5-minute rate `cacheWrite` is 1.25 ×). A table row without a 1-hour
     * price charges those tokens at twice its input price.
     */
    public function costMicroUsd(string $model, int $input, int $output, int $cacheRead, int $cacheWrite, int $cacheWrite1h = 0): null|int
    {
        $prices = $this->definition()['prices'] ?? null;
        $models = is_array($prices) ? ($prices['models'] ?? null) : null;
        $price = is_array($models) ? ($models[$model] ?? null) : null;

        if (!is_array($price)) {
            return null;
        }

        $perMillion = static fn (string $key): float => is_numeric($price[$key] ?? null) ? (float) $price[$key] : 0.0;

        $oneHour = max(0, min($cacheWrite1h, $cacheWrite));
        $oneHourPrice = is_numeric($price['cacheWrite1h'] ?? null) ? (float) $price['cacheWrite1h'] : 2 * $perMillion('input');

        // USD per million tokens × tokens = micro-USD.
        return (int) round(
            $input * $perMillion('input')
            + $output * $perMillion('output')
            + $cacheRead * $perMillion('cacheRead')
            + ($cacheWrite - $oneHour) * $perMillion('cacheWrite')
            + $oneHour * $oneHourPrice,
        );
    }

    /** Changes whenever any file of the definition changes. */
    public function version(): string
    {
        $declared = $this->definition()['version'] ?? null;

        return sprintf(
            '%s-%s',
            is_string($declared) && $declared !== '' ? substr($declared, 0, 16) : '0',
            substr(hash('sha256', (string) json_encode($this->files())), 0, 12),
        );
    }

    /**
     * Every file of the definition directory, name → content — what a claim
     * answer carries.
     *
     * @return array<string, string>
     */
    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $files = [];
        $paths = glob($this->directory . '/*') ?: [];
        sort($paths);

        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }

            $content = file_get_contents($path);

            if ($content !== false) {
                $files[basename($path)] = $content;
            }
        }

        return $this->files = $files;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        if ($this->definition !== null) {
            return $this->definition;
        }

        $json = $this->files()['definition.json'] ?? '{}';
        $decoded = json_decode($json, true);

        /** @var array<string, mixed> $definition */
        $definition = is_array($decoded) ? $decoded : [];

        return $this->definition = $definition;
    }

    private function cap(string $name): mixed
    {
        $caps = $this->definition()['caps'] ?? null;

        return is_array($caps) ? ($caps[$name] ?? null) : null;
    }

    private static function int(mixed $value, int $default): int
    {
        return is_int($value) ? $value : $default;
    }
}
