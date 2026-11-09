<?php

declare(strict_types=1);

namespace WBoost\Web\Value\ManualContent;

/**
 * What a manual's rich text may be styled with: the manual's font faces
 * (as the CSS family the manual declares per face — `'<font> (<face>)'`,
 * see `manual_preview.html.twig`) and the project palette colours.
 * Built by {@see \WBoost\Web\Services\Manual\ResolveManualContentStyles}.
 */
readonly final class ManualContentStyles
{
    /**
     * @param list<array{family: string, label: string, url?: string}> $fonts
     * @param list<array{color: string, name: string}> $colors `#rrggbb` lowercase
     */
    public function __construct(
        public array $fonts,
        public array $colors,
    ) {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    public function allowsFont(string $family): bool
    {
        foreach ($this->fonts as $font) {
            if ($font['family'] === $family) {
                return true;
            }
        }

        return false;
    }

    public function allowsColor(string $color): bool
    {
        $color = RichTextDoc::normalizeColor($color);

        if ($color === null) {
            return false;
        }

        foreach ($this->colors as $entry) {
            if ($entry['color'] === $color) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $mark a canonical mark from {@see RichTextDoc}
     */
    public function allowsMark(array $mark): bool
    {
        $attrs = is_array($mark['attrs'] ?? null) ? $mark['attrs'] : [];

        return match ($mark['type'] ?? null) {
            'font' => is_string($attrs['family'] ?? null) && $this->allowsFont($attrs['family']),
            'color' => is_string($attrs['color'] ?? null) && $this->allowsColor($attrs['color']),
            default => true,
        };
    }
}
