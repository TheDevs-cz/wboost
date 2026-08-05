<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * What a rich-text WYSIWYG may offer for one template variant: the pickable
 * font faces (whitelist for {@see RichTextRun::$fontFamily}) and the brand
 * color swatches. Produced ONLY by
 * {@see \WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions} — the
 * single source of truth shared by the fill page, the API listing, and
 * export-time validation, so they can never disagree.
 *
 * Colors are suggestions: any well-formed hex color is accepted by the
 * render contract, the swatches just surface the brand palette.
 */
readonly final class RichTextOptions
{
    /**
     * @param list<RichTextFontOption> $fonts
     * @param list<string> $colors lowercase `#rrggbb`, primary brand colors first
     */
    public function __construct(
        public array $fonts,
        public array $colors,
    ) {
    }

    /**
     * @return list<string>
     */
    public function allowedFamilies(): array
    {
        return array_map(
            static fn (RichTextFontOption $font): string => $font->family,
            $this->fonts,
        );
    }

    /**
     * @return array{fonts: list<array{family: string, fontName: string, faceName: string, weight: int, style: string, url: string}>, colors: list<string>}
     */
    public function toArray(): array
    {
        return [
            'fonts' => array_map(
                static fn (RichTextFontOption $font): array => $font->toArray(),
                $this->fonts,
            ),
            'colors' => $this->colors,
        ];
    }

    /**
     * The WYSIWYG toolbar payload: toArray() plus the faces grouped by font
     * for the <optgroup> dropdown. Shared by the fill page and the admin
     * editor's "Vzorový text" modal so both toolbars are built identically.
     *
     * @return array{fonts: list<array{family: string, fontName: string, faceName: string, weight: int, style: string, url: string}>, colors: list<string>, fontGroups: list<array{name: string, faces: list<array{family: string, faceName: string}>}>}
     */
    public function toToolbarArray(): array
    {
        /** @var array<string, list<array{family: string, faceName: string}>> $grouped */
        $grouped = [];
        foreach ($this->fonts as $font) {
            $grouped[$font->fontName][] = ['family' => $font->family, 'faceName' => $font->faceName];
        }

        $fontGroups = [];
        foreach ($grouped as $name => $faces) {
            $fontGroups[] = ['name' => $name, 'faces' => $faces];
        }

        return [...$this->toArray(), 'fontGroups' => $fontGroups];
    }
}
