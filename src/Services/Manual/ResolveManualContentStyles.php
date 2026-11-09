<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Manual;

use WBoost\Web\Entity\Manual;
use WBoost\Web\Query\GetManualFonts;
use WBoost\Web\Query\GetProjectPalette;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\ManualContent\ManualContentStyles;
use WBoost\Web\Value\ManualContent\RichTextDoc;

/**
 * The fonts and colours a manual's rich text may use:
 *
 * - every face of every font the manual presents (`Font::faceFamily()`, the
 *   family `manual_preview.html.twig` registers its `@font-face` under) — all
 *   faces, not just the enabled cuts, so switching a cut off on the font
 *   page does not silently restyle text;
 * - the project palette as the rest of the app reads it
 *   (`GetProjectPalette::forProject()`, colours at least one manual links).
 */
readonly final class ResolveManualContentStyles
{
    public function __construct(
        private GetManualFonts $getManualFonts,
        private GetProjectPalette $getProjectPalette,
        private UploaderHelper $uploaderHelper,
    ) {
    }

    public function forManual(Manual $manual): ManualContentStyles
    {
        $fonts = [];

        foreach ($this->getManualFonts->allForManual($manual->id) as $manualFont) {
            foreach ($manualFont->font->faces as $face) {
                $family = $manualFont->font->faceFamily($face);
                $fonts[$family] = ['family' => $family, 'label' => $face->name, 'url' => $this->uploaderHelper->getPublicPath($face->filePath)];
            }
        }

        $colors = [];

        foreach ($this->getProjectPalette->forProject($manual->project->id) as $projectColor) {
            $color = RichTextDoc::normalizeColor($projectColor->hexKey) ?? RichTextDoc::normalizeColor($projectColor->hex);

            if ($color === null || isset($colors[$color])) {
                continue;
            }

            $colors[$color] = [
                'color' => $color,
                'name' => $projectColor->name ?? strtoupper(ltrim($projectColor->hex, '#')),
            ];
        }

        return new ManualContentStyles(array_values($fonts), array_values($colors));
    }
}
