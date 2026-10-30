<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Query\GetManuals;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Value\CmykColor;
use WBoost\Web\Value\RichText;

/**
 * The project's brand colours with the CMYK the manuals specify for them —
 * the "keep the numbers" half of the print conversion: a pixel painted in a
 * brand colour gets the manual's exact ink values instead of an ICC guess.
 *
 * Union of every project manual, primary → secondary → untyped (the same
 * order the editor offers them in). When two manuals give one hex different
 * CMYK values, the first wins and the conflict is logged. Colours without a
 * usable CMYK (blank, unparseable, implausible for the hex) are left out and
 * simply go through the ICC conversion.
 */
readonly final class BrandCmykPalette
{
    public function __construct(
        private GetManuals $getManuals,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, CmykColor> lowercase `#rrggbb` → manual CMYK
     */
    public function forProject(UuidInterface $projectId): array
    {
        return $this->fromManuals($this->getManuals->allForProject($projectId));
    }

    /**
     * @param array<Manual> $manuals
     * @return array<string, CmykColor>
     */
    public function fromManuals(array $manuals): array
    {
        $palette = [];

        foreach (ResolveRichTextOptions::orderedManualColors($manuals) as $manualColor) {
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
