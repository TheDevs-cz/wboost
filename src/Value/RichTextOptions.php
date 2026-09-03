<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * What the text inputs of one template variant may use when the end user
 * formats or switches fonts: the pickable font faces PER INPUT, the union of
 * those faces (the variant-level whitelist the API's `richTextOptions`
 * exposes) and the brand color swatches. Produced ONLY by
 * {@see \WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions} — the
 * single source of truth shared by the fill pages, the API listing, and
 * export-time validation, so they can never disagree.
 *
 * Per input, the faces are: the designed font (a rich input gets every face of
 * its family so bold / italic work; a plain input its exact designed face)
 * plus whatever the designer opened up in `EditorTextInput::$allowedFonts`.
 * A run's `fontFamily` and the whole-text `fontFamily` override are both
 * validated against the INPUT's list ({@see allowedFamiliesFor()}), never the
 * union — the admin's "these fonts for this input" must hold for API
 * consumers too, not just for the web toolbar.
 *
 * Colors are suggestions: any well-formed hex color is accepted by the
 * render contract, the swatches just surface the brand palette.
 */
readonly final class RichTextOptions
{
    /**
     * @param list<RichTextFontOption> $fonts union of the faces every
     *   fillable RICH input may use (whitelist for {@see RichTextRun::$fontFamily}
     *   when the input has no entry of its own)
     * @param list<string> $colors lowercase `#rrggbb`, primary brand colors first
     * @param array<string, list<RichTextFontOption>> $fontsByInput inputId →
     *   the faces THAT input may use (rich inputs: the WYSIWYG offer; plain
     *   inputs: the designed face plus the admin's extra picks)
     * @param array<string, null|string> $designedByInput inputId → the canvas
     *   textbox's designed `fontFamily` (null = not locatable)
     */
    public function __construct(
        public array $fonts,
        public array $colors,
        public array $fontsByInput = [],
        public array $designedByInput = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function allowedFamilies(): array
    {
        return self::families($this->fonts);
    }

    /**
     * The whitelist for ONE input. An input this options object does not
     * know (a test-built instance, an id from another variant) falls back to
     * the union so validation stays as strict as it used to be, never looser.
     *
     * @return list<string>
     */
    public function allowedFamiliesFor(string $inputId): array
    {
        return self::families($this->fontOptionsFor($inputId));
    }

    /**
     * @return list<RichTextFontOption>
     */
    public function fontOptionsFor(string $inputId): array
    {
        return $this->fontsByInput[$inputId] ?? $this->fonts;
    }

    /**
     * The designed face as an option — null when the canvas font is not a
     * project face (a bare font name, a system font, an unlocatable box).
     */
    public function designedFontFor(string $inputId): null|RichTextFontOption
    {
        $designed = $this->designedByInput[$inputId] ?? null;

        if ($designed === null) {
            return null;
        }

        foreach ($this->fontOptionsFor($inputId) as $option) {
            if ($option->family === $designed) {
                return $option;
            }
        }

        return null;
    }

    /**
     * The faces the user may SWITCH a plain input to — its options minus the
     * designed face. Empty = there is nothing to choose, so no font select.
     *
     * @return list<RichTextFontOption>
     */
    public function switchableFontsFor(string $inputId): array
    {
        $designed = $this->designedByInput[$inputId] ?? null;

        return array_values(array_filter(
            $this->fontOptionsFor($inputId),
            static fn (RichTextFontOption $option): bool => $option->family !== $designed,
        ));
    }

    /**
     * Whether a plain input actually offers a whole-text font switch: the
     * designer opened it up AND at least one extra face resolved.
     */
    public function offersFontSwitch(EditorTextInput $input): bool
    {
        return !$input->locked && $input->offersFontChoice() && $this->switchableFontsFor($input->inputId) !== [];
    }

    /**
     * The faces grouped by font for an <optgroup> dropdown — the shape both
     * the fill-page WYSIWYG / font select and the admin editor's "Vzorový
     * text" modal build their menus from.
     *
     * @param list<RichTextFontOption> $options
     * @return list<array{name: string, faces: list<array{family: string, faceName: string}>}>
     */
    public static function groupFaces(array $options): array
    {
        /** @var array<string, list<array{family: string, faceName: string}>> $grouped */
        $grouped = [];
        foreach ($options as $font) {
            $grouped[$font->fontName][] = ['family' => $font->family, 'faceName' => $font->faceName];
        }

        $fontGroups = [];
        foreach ($grouped as $name => $faces) {
            $fontGroups[] = ['name' => $name, 'faces' => $faces];
        }

        return $fontGroups;
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
        return [...$this->toArray(), 'fontGroups' => self::groupFaces($this->fonts)];
    }

    /**
     * @param list<RichTextFontOption> $options
     * @return list<string>
     */
    private static function families(array $options): array
    {
        return array_map(
            static fn (RichTextFontOption $font): string => $font->family,
            $options,
        );
    }
}
