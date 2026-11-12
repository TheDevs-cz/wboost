<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

/**
 * Note codes (architecture.md §5.6). A note never carries free text: only the
 * integer parameters named here survive {@see self::params()}.
 */
enum AiScanNote: string
{
    case TextsFound = 'texts_found';
    case RowsFound = 'rows_found';
    case Zooming = 'zooming';
    case Measuring = 'measuring';
    case Fonts = 'fonts';
    case ProviderRetry = 'provider_retry';

    /**
     * @return list<string>
     */
    public function paramNames(): array
    {
        return match ($this) {
            self::TextsFound, self::RowsFound => ['n'],
            self::ProviderRetry => ['n', 'of'],
            default => [],
        };
    }

    /**
     * @return array<string, int>
     */
    public function params(mixed $raw): array
    {
        $params = [];

        foreach ($this->paramNames() as $name) {
            $value = is_array($raw) ? ($raw[$name] ?? null) : null;
            $params[$name] = is_int($value) ? max(0, min($value, 100000)) : 0;
        }

        return $params;
    }
}
