<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Calendar;

use WBoost\Web\Entity\Calendar;
use WBoost\Web\Services\Calendar\CalendarPageRenderer;
use WBoost\Web\Value\Calendar\CalendarPageRender;
use WBoost\Web\Value\RenderImageFormat;

/**
 * A candidate's picture as the scanner compares it with the source: the
 * trimmed page (no bleed, no marks), 1600 px on the long edge, with the
 * designer's sample texts — the sample caption, the LOGO / QR placeholders —
 * so every element the layout places is visible. WebP, like the template
 * candidates.
 */
final readonly class CalendarCandidatePicture implements CalendarCandidatePictureInterface
{
    public const int LONG_EDGE = 1600;

    public function __construct(
        private CalendarPageRenderer $renderer,
    ) {
    }

    public function picture(Calendar $calendar, array $document, string $pageKey): CalendarPageRender
    {
        return $this->renderer->renderDocument(
            $calendar,
            $document,
            $pageKey,
            sampleTexts: true,
            longEdge: self::LONG_EDGE,
            format: RenderImageFormat::Webp,
        );
    }
}
