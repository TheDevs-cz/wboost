<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Calendar;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Calendar\BackfillCalendarPhotoCaptions;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Services\Calendar\CalendarContentRules;
use WBoost\Web\Services\Calendar\CalendarHistoryWriter;
use WBoost\Web\Value\Calendar\CalendarActor;
use WBoost\Web\Value\Calendar\CalendarContentOp;

/**
 * Writes the backfilled captions through the content rules, under the row
 * lock (`getForUpdate`), bumps the revision and records ONE history line —
 * the same merge the workspace's save does, item-wise, so nothing else of the
 * calendar changes. A caption that is no longer empty, or a page whose photo
 * is gone, is skipped.
 */
#[AsMessageHandler]
readonly final class BackfillCalendarPhotoCaptionsHandler
{
    public const string HISTORY_SENTENCE = 'Doplnil popisky z názvů souborů';

    public function __construct(
        private CalendarRepository $calendarRepository,
        private CalendarContentRules $contentRules,
        private CalendarHistoryWriter $history,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(BackfillCalendarPhotoCaptions $message): void
    {
        $calendar = $this->calendarRepository->getForUpdate($message->calendarId);
        $actor = CalendarActor::designer();
        $now = $this->clock->now();
        $content = $calendar->content;
        $photos = is_array($content['pagePhotos'] ?? null) ? $content['pagePhotos'] : [];
        $captions = is_array($content['pageCaptions'] ?? null) ? $content['pageCaptions'] : [];
        $written = 0;

        foreach ($message->captions as $pageKey => $text) {
            $pageKey = (string) $pageKey;
            $stored = is_array($captions[$pageKey] ?? null) ? $captions[$pageKey] : null;

            if (!isset($photos[$pageKey]) || self::hasText($stored)) {
                continue;
            }

            $op = new CalendarContentOp('pageCaptions', $pageKey, ['text' => $text]);
            $item = $this->contentRules->validate($op, $actor, $calendar->year, [], $stored, $now, $captions);

            if ($item === null) {
                continue;
            }

            $captions[$pageKey] = $item;
            $written++;
        }

        if ($written === 0) {
            return;
        }

        $content['pageCaptions'] = $captions;
        $calendar->replaceContent($content);
        $this->history->record($calendar, $actor, sprintf('%s (%d)', self::HISTORY_SENTENCE, $written), $now);
        $calendar->touch($now);
        $this->history->prune($calendar);
    }

    /**
     * @param null|array<mixed> $caption
     */
    public static function hasText(null|array $caption): bool
    {
        return $caption !== null && is_string($caption['text'] ?? null) && trim($caption['text']) !== '';
    }
}
