<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Entity\Font;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Query\GetManuals;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\ManualColor;
use WBoost\Web\Value\RichText;
use WBoost\Web\Value\RichTextFontOption;
use WBoost\Web\Value\RichTextOptions;

/**
 * Single source of truth for what a rich-text (WYSIWYG) placeholder may
 * offer, per variant. Used by the fill page (toolbar), the API listing
 * (`richTextOptions`), and export-time validation (font whitelist) — the
 * PlaceholderAllowedDirectories pattern, so the surfaces can never disagree.
 *
 * Fonts: the font FAMILIES already used in the variant's canvas, expanded to
 * ALL uploaded faces of those families (bold/italic live as separate faces —
 * a canvas typically uses only the regular one). When no canvas family
 * matches a project font (e.g. everything still sits on the Fabric default),
 * fall back to all project fonts. A font renamed/deleted after the canvas was
 * saved simply stops matching and may trigger that fallback.
 *
 * Colors: the union of brand manual colors across all project manuals,
 * primary first, then secondary, then untyped, deduped by normalized hex.
 */
readonly final class ResolveRichTextOptions
{
    public function __construct(
        private GetFonts $getFonts,
        private GetManuals $getManuals,
        private UploaderHelper $uploaderHelper,
    ) {
    }

    public function forVariant(SocialNetworkTemplateVariant|CustomTemplateVariant $variant): RichTextOptions
    {
        $project = $variant->template->project;

        return new RichTextOptions(
            fonts: self::computeFonts(
                $this->getFonts->allForProject($project->id),
                $variant->canvas,
                $this->uploaderHelper,
            ),
            colors: self::computeColors($this->getManuals->allForProject($project->id)),
        );
    }

    /**
     * Pure core, unit-tested directly.
     *
     * @param array<Font> $projectFonts
     * @return list<RichTextFontOption>
     */
    public static function computeFonts(array $projectFonts, string $canvasJson, UploaderHelper $uploaderHelper): array
    {
        $canvasFamilies = self::canvasFontFamilies($canvasJson);

        $usedFonts = array_values(array_filter(
            $projectFonts,
            static function (Font $font) use ($canvasFamilies): bool {
                foreach ($canvasFamilies as $family) {
                    if ($family === $font->name || str_starts_with($family, $font->name . ' (')) {
                        return true;
                    }
                }

                return false;
            },
        ));

        if ($usedFonts === []) {
            $usedFonts = array_values($projectFonts);
        }

        $options = [];

        foreach ($usedFonts as $font) {
            foreach ($font->faces as $face) {
                $options[] = new RichTextFontOption(
                    family: sprintf('%s (%s)', $font->name, $face->name),
                    fontName: $font->name,
                    faceName: $face->name,
                    weight: $face->weight,
                    style: $face->style,
                    url: $uploaderHelper->getPublicPath($face->filePath),
                );
            }
        }

        return $options;
    }

    /**
     * Pure core, unit-tested directly.
     *
     * @param array<Manual> $manuals
     * @return list<string> lowercase `#rrggbb`, primary → secondary → untyped
     */
    public static function computeColors(array $manuals): array
    {
        /** @var list<ManualColor> $ordered */
        $ordered = [];

        foreach ($manuals as $manual) {
            foreach ($manual->primaryColors() as $color) {
                $ordered[] = $color;
            }
        }

        foreach ($manuals as $manual) {
            foreach ($manual->secondaryColors() as $color) {
                $ordered[] = $color;
            }
        }

        foreach ($manuals as $manual) {
            foreach (array_merge($manual->detectedColors(), $manual->customColors) as $color) {
                if ($color->type === null) {
                    $ordered[] = $color;
                }
            }
        }

        $colors = [];

        foreach ($ordered as $manualColor) {
            $normalized = RichText::normalizeHexColor($manualColor->color->hex);

            if ($normalized !== null && !in_array($normalized, $colors, true)) {
                $colors[] = $normalized;
            }
        }

        return $colors;
    }

    /**
     * @return list<string>
     */
    private static function canvasFontFamilies(string $canvasJson): array
    {
        $decoded = json_decode($canvasJson, true);

        if (!is_array($decoded)) {
            return [];
        }

        $objects = $decoded['objects'] ?? [];

        if (!is_array($objects)) {
            return [];
        }

        $families = [];

        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }

            $type = $object['type'] ?? null;

            if (!is_string($type) || strtolower($type) !== 'textbox') {
                continue;
            }

            $family = $object['fontFamily'] ?? null;

            if (is_string($family) && $family !== '' && !in_array($family, $families, true)) {
                $families[] = $family;
            }
        }

        return $families;
    }
}
