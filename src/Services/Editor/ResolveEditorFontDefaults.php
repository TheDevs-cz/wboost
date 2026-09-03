<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Editor;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Font;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualFont;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Query\GetManuals;
use WBoost\Web\Value\EditorFontDefaults;
use WBoost\Web\Value\FontFace;

/**
 * Bridges the brand manual's font settings (primary / secondary font, the
 * faces a manual enables) into the canvas editor, which used to ignore them:
 * a new text started in whichever face was uploaded first.
 *
 * The default face for a new text is the PRIMARY manual font's regular cut
 * — upright, weight closest to 400, among the faces the manual enables
 * (every face when the manual enables none) — falling back to the secondary
 * font, then to the first project font, then to nothing (the editor then
 * uses its "Arial" default). `manualFaces` is the union of enabled faces
 * across the project's manuals, which the font allowlist offers as a
 * one-click preset.
 */
readonly final class ResolveEditorFontDefaults
{
    public function __construct(
        private GetManuals $getManuals,
        private GetFonts $getFonts,
    ) {
    }

    public function forProject(UuidInterface $projectId): EditorFontDefaults
    {
        return self::compute($this->getManuals->allForProject($projectId), $this->getFonts->allForProject($projectId));
    }

    /**
     * Pure core, unit-tested directly.
     *
     * @param array<Manual> $manuals
     * @param array<Font> $projectFonts
     */
    public static function compute(array $manuals, array $projectFonts): EditorFontDefaults
    {
        /** @var list<ManualFont> $primary */
        $primary = [];
        /** @var list<ManualFont> $secondary */
        $secondary = [];

        foreach ($manuals as $manual) {
            $manualFonts = $manual->fonts->toArray();
            usort($manualFonts, static fn (ManualFont $a, ManualFont $b): int => $a->position <=> $b->position);

            foreach ($manualFonts as $manualFont) {
                if ($manualFont->isPrimary()) {
                    $primary[] = $manualFont;
                } elseif ($manualFont->isSecondary()) {
                    $secondary[] = $manualFont;
                }
            }
        }

        $manualFaces = [];
        $defaultFamily = null;

        foreach ([...$primary, ...$secondary] as $manualFont) {
            $font = $manualFont->font;
            $enabled = self::enabledFaces($manualFont);

            foreach ($enabled as $face) {
                $family = $font->faceFamily($face);
                if (!in_array($family, $manualFaces, true)) {
                    $manualFaces[] = $family;
                }
            }

            if ($defaultFamily === null && $enabled !== []) {
                $defaultFamily = $font->faceFamily(self::regularCut($enabled));
            }
        }

        if ($defaultFamily === null) {
            foreach ($projectFonts as $font) {
                if ($font->faces !== []) {
                    $defaultFamily = $font->faceFamily(self::regularCut(array_values($font->faces)));
                    break;
                }
            }
        }

        return new EditorFontDefaults($defaultFamily, $manualFaces);
    }

    /**
     * The faces a manual enables for its font — all of them when it enables
     * none (an untouched manual font means "the whole font").
     *
     * @return list<FontFace>
     */
    private static function enabledFaces(ManualFont $manualFont): array
    {
        $faces = array_values($manualFont->font->faces);
        $enabled = array_values(array_filter($faces, static fn (FontFace $face): bool => $manualFont->faceEnabled($face->name)));

        return $enabled !== [] ? $enabled : $faces;
    }

    /**
     * Upright first, weight closest to 400 — "Regular" by metadata rather
     * than by name, which uploads spell a dozen ways.
     *
     * @param list<FontFace> $faces non-empty
     */
    private static function regularCut(array $faces): FontFace
    {
        $best = $faces[0];
        $bestScore = PHP_INT_MAX;

        foreach ($faces as $face) {
            $score = abs($face->weight - 400) + ($face->isItalic() ? 1000 : 0);
            if ($score < $bestScore) {
                $best = $face;
                $bestScore = $score;
            }
        }

        return $best;
    }
}
