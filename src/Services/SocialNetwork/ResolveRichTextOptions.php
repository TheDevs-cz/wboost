<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Entity\Font;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Query\GetManuals;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\ManualColor;
use WBoost\Web\Value\RichText;
use WBoost\Web\Value\RichTextFontOption;
use WBoost\Web\Value\RichTextOptions;

/**
 * Single source of truth for which fonts each text input of a variant may be
 * filled in, and which color swatches the WYSIWYG offers. Used by the fill
 * pages (per-input font select / WYSIWYG toolbar), the API listing
 * (`inputs[].fontOptions` + the `richTextOptions` union), and export-time
 * validation (font whitelist) — the PlaceholderAllowedDirectories pattern, so
 * the surfaces can never disagree.
 *
 * Fonts, PER INPUT ({@see computeInputFonts()}):
 *  - the DESIGNED font comes first and is always offered: a rich input gets
 *    every uploaded face of its family (bold / italic are separate faces here
 *    — the B/I buttons switch between them), a plain input its exact face;
 *  - then the faces the designer opened up in `EditorTextInput::$allowedFonts`
 *    ("Uživatel může přepínat písmo"), in project-font order — a pick that no
 *    longer matches an uploaded face (renamed / deleted font) is dropped;
 *  - a rich input whose designed font is not a project font and that has no
 *    extra picks falls back to ALL project fonts (the pre-choice behaviour —
 *    without it the WYSIWYG's face buttons would have nothing to switch to).
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
        private TextInputObjectBinder $textInputObjectBinder,
    ) {
    }

    public function forVariant(TemplateVariant $variant): RichTextOptions
    {
        $project = $variant->template->project;
        $faces = self::projectFaces($this->getFonts->allForProject($project->id), $this->uploaderHelper);

        $decoded = json_decode($variant->canvas, true);
        $canvas = is_array($decoded) ? $decoded : [];
        $styles = $this->textInputObjectBinder->textStylesByInputId($canvas, $variant->inputs);

        $designedFamilies = [];
        foreach ($styles as $inputId => $style) {
            $designedFamilies[$inputId] = $style['fontFamily'];
        }

        return self::compute(
            $faces,
            $variant->inputs,
            $designedFamilies,
            self::computeColors($this->getManuals->allForProject($project->id)),
        );
    }

    /**
     * Every face of every project font — what the admin editor lets the
     * designer pick from ("Uživatel může přepínat písmo") and what its
     * "Vzorový text" WYSIWYG narrows per input client-side.
     */
    public function forProject(UuidInterface $projectId): RichTextOptions
    {
        return new RichTextOptions(
            fonts: self::projectFaces($this->getFonts->allForProject($projectId), $this->uploaderHelper),
            colors: self::computeColors($this->getManuals->allForProject($projectId)),
        );
    }

    /**
     * Pure core, unit-tested directly.
     *
     * @param list<RichTextFontOption> $faces {@see projectFaces()}
     * @param array<EditorTextInput> $inputs
     * @param array<string, null|string> $designedFamilies inputId → the canvas
     *   textbox's `fontFamily` (missing / null = the box could not be located)
     * @param list<string> $colors
     */
    public static function compute(array $faces, array $inputs, array $designedFamilies, array $colors): RichTextOptions
    {
        $fontsByInput = [];
        $designedByInput = [];
        /** @var array<string, RichTextFontOption> $union family → option, project order */
        $union = [];

        foreach ($inputs as $input) {
            if ($input->locked) {
                continue;
            }

            $designedFamily = $designedFamilies[$input->inputId] ?? null;
            $options = self::computeInputFonts($faces, $designedFamily, $input);
            $fontsByInput[$input->inputId] = $options;
            $designedByInput[$input->inputId] = $designedFamily !== '' ? $designedFamily : null;

            if ($input->richText) {
                foreach ($options as $option) {
                    $union[$option->family] = $option;
                }
            }
        }

        // The union keeps project-font order regardless of which input
        // contributed a face first.
        $fonts = array_values(array_filter(
            $faces,
            static fn (RichTextFontOption $face): bool => isset($union[$face->family]),
        ));

        return new RichTextOptions($fonts, $colors, $fontsByInput, $designedByInput);
    }

    /**
     * The faces ONE input may be filled in — see the class docblock for the
     * rules. Designed font first, then the designer's extra picks in
     * project-font order.
     *
     * @param list<RichTextFontOption> $faces
     * @return list<RichTextFontOption>
     */
    public static function computeInputFonts(array $faces, null|string $designedFamily, EditorTextInput $input): array
    {
        $designedFace = null;
        $designedFontName = null;

        if ($designedFamily !== null && $designedFamily !== '') {
            foreach ($faces as $face) {
                if ($face->family === $designedFamily) {
                    $designedFace = $face;
                    $designedFontName = $face->fontName;
                    break;
                }
            }

            // A bare font name on the canvas (no face suffix) still names the
            // font — offer its faces, since nothing says which one is meant.
            if ($designedFace === null) {
                foreach ($faces as $face) {
                    if ($face->fontName === $designedFamily) {
                        $designedFontName = $face->fontName;
                        break;
                    }
                }
            }
        }

        $base = [];
        foreach ($faces as $face) {
            $isDesigned = $designedFace !== null
                ? ($input->richText ? $face->fontName === $designedFontName : $face->family === $designedFace->family)
                : ($designedFontName !== null && $face->fontName === $designedFontName);

            if ($isDesigned) {
                $base[] = $face;
            }
        }

        $extras = [];
        foreach ($faces as $face) {
            if (in_array($face->family, $input->allowedFonts, true) && !in_array($face, $base, true)) {
                $extras[] = $face;
            }
        }

        $options = [...$base, ...$extras];

        if ($options === [] && $input->richText) {
            return $faces;
        }

        return $options;
    }

    /**
     * Every uploaded face of every project font, project order, as toolbar
     * options.
     *
     * @param array<Font> $projectFonts
     * @return list<RichTextFontOption>
     */
    public static function projectFaces(array $projectFonts, UploaderHelper $uploaderHelper): array
    {
        $options = [];

        foreach ($projectFonts as $font) {
            foreach ($font->faces as $face) {
                $options[] = new RichTextFontOption(
                    family: $font->faceFamily($face),
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
}
