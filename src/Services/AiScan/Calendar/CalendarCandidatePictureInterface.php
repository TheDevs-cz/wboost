<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Calendar;

use WBoost\Web\Entity\Calendar;
use WBoost\Web\Exceptions\CalendarModelRefused;
use WBoost\Web\Exceptions\CalendarModelUnavailable;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Value\Calendar\CalendarPageRender;

/**
 * The picture of a calendar candidate: one page of an UNSAVED calendar
 * document. The real one is a Gotenberg render
 * ({@see CalendarCandidatePicture}); the test suite replaces it.
 */
interface CalendarCandidatePictureInterface
{
    /**
     * @param array<string, mixed> $document the shape of {@see \WBoost\Web\Services\Calendar\CalendarDocument::calendar()}
     *
     * @throws CalendarModelRefused
     * @throws CalendarModelUnavailable
     * @throws TemplateRenderUnavailable
     */
    public function picture(Calendar $calendar, array $document, string $pageKey): CalendarPageRender;
}
