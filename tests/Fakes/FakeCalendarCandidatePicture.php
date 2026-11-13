<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Fakes;

use WBoost\Web\Entity\Calendar;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Services\AiScan\Calendar\CalendarCandidatePictureInterface;
use WBoost\Web\Value\Calendar\CalendarPageRender;

/**
 * No Gotenberg in the suite: a 1 × 1 WebP for a calendar candidate. The
 * document it was asked to draw is kept for the test to look at.
 */
final class FakeCalendarCandidatePicture implements CalendarCandidatePictureInterface
{
    /**
     * Static on purpose: a `KernelBrowser` boots a new container for every
     * request, and the test reads what an earlier request was asked to draw.
     *
     * @var list<array{calendarId: string, document: array<string, mixed>, page: string}>
     */
    private static array $calls = [];

    public bool $unavailable = false;

    public static function forget(): void
    {
        self::$calls = [];
    }

    /**
     * @return null|array{calendarId: string, document: array<string, mixed>, page: string}
     */
    public static function lastCall(): null|array
    {
        return self::$calls === [] ? null : self::$calls[array_key_last(self::$calls)];
    }

    public function picture(Calendar $calendar, array $document, string $pageKey): CalendarPageRender
    {
        if ($this->unavailable) {
            throw TemplateRenderUnavailable::timedOut(new \RuntimeException('fake'));
        }

        self::$calls[] = ['calendarId' => $calendar->id->toString(), 'document' => $document, 'page' => $pageKey];
        $page = ['index' => 2, 'key' => $pageKey, 'kind' => 'week', 'title' => '2. týden', 'subtitle' => ''];

        return new CalendarPageRender(
            bytes: (string) base64_decode('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=', true),
            width: 1600,
            height: 748,
            page: $page,
            pages: [$page],
            warnings: [],
            view: ['x' => 0.0, 'y' => 0.0, 'w' => 310.0, 'h' => 145.0],
        );
    }
}
