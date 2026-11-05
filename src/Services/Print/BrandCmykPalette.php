<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Query\GetProjectPalette;
use WBoost\Web\Value\CmykColor;
use WBoost\Web\Value\ManualColor;
use WBoost\Web\Value\RichText;

/**
 * The project's brand colours with the CMYK the manuals specify for them —
 * the "keep the numbers" half of the print conversion: a pixel painted in a
 * brand colour gets the manual's exact ink values instead of an ICC guess.
 *
 * The project palette in palette order ({@see GetProjectPalette} — the colours
 * some manual links, the order the editor offers them in; F7 R2). When two
 * palette colours of one hex carry different CMYK (split duplicates), the
 * first wins and the conflict is logged. Colours without a usable CMYK
 * (blank, unparseable, implausible for the hex) are left out and simply go
 * through the ICC conversion.
 */
readonly final class BrandCmykPalette
{
    public function __construct(
        private GetProjectPalette $getProjectPalette,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, CmykColor> lowercase `#rrggbb` → manual CMYK
     */
    public function forProject(UuidInterface $projectId): array
    {
        return $this->fromPalette($this->getProjectPalette->colorsForProject($projectId));
    }

    /**
     * @param array<ManualColor> $colors palette order
     * @return array<string, CmykColor>
     */
    public function fromPalette(array $colors): array
    {
        $palette = [];

        foreach ($colors as $manualColor) {
            $hex = RichText::normalizeHexColor($manualColor->color->hex);
            $cmyk = CmykColor::fromManualColor($manualColor);

            if ($hex === null || $cmyk === null) {
                continue;
            }

            if (isset($palette[$hex])) {
                if ($palette[$hex] != $cmyk) {
                    $this->logger->warning('Brand colour {hex} has conflicting CMYK values across manuals; using the first.', ['hex' => $hex]);
                }

                continue;
            }

            $palette[$hex] = $cmyk;
        }

        return $palette;
    }
}
