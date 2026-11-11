<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use Symfony\Component\HttpFoundation\JsonResponse;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Repository\CalendarHistoryRepository;

/**
 * The Historie list behind both history routes: the latest 200 lines,
 * newest first, as `{ entries: [{ who: 'Grafik'|'Klient', what, at }] }`.
 */
readonly final class CalendarHistoryList
{
    public const int LIMIT = 200;

    public function __construct(
        private CalendarHistoryRepository $historyRepository,
    ) {
    }

    public function response(Calendar $calendar): JsonResponse
    {
        $entries = [];

        foreach ($this->historyRepository->latest($calendar->id, self::LIMIT) as $entry) {
            $entries[] = [
                'who' => $entry->authorKind->label(),
                'what' => $entry->description,
                'at' => $entry->createdAt->format(DATE_ATOM),
            ];
        }

        return new JsonResponse(['entries' => $entries]);
    }
}
