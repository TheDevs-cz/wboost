<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print\Cmyk;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A PDF dictionary: key (without the slash) → value. Values are what
 * {@see PdfSyntax::parse()} gives: a `PdfDict`, a `list` (array), a
 * {@see PdfName}, {@see PdfRef}, {@see PdfString}, or a PHP string holding a
 * number / `true` / `false` / `null` exactly as it was written.
 */
#[Exclude]
final class PdfDict
{
    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(
        public array $entries = [],
    ) {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->entries);
    }

    public function get(string $key): mixed
    {
        return $this->entries[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->entries[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->entries[$key]);
    }

    /** The value of a name entry (`/Subtype /Image` → `Image`), or null. */
    public function name(string $key): null|string
    {
        $value = $this->entries[$key] ?? null;

        return $value instanceof PdfName ? $value->value : null;
    }
}
