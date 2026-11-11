<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Calendar;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Exceptions\CalendarChangeForbidden;
use WBoost\Web\Exceptions\CalendarNotFound;
use WBoost\Web\Exceptions\InvalidCalendarChange;
use WBoost\Web\Message\Calendar\SaveCalendar;
use WBoost\Web\Repository\CalendarAssetRepository;
use WBoost\Web\Repository\CalendarHistoryRepository;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Services\Calendar\CalendarContentRules;
use WBoost\Web\Services\Calendar\CalendarDesignRules;
use WBoost\Web\Services\Calendar\CalendarHistoryWriter;
use WBoost\Web\Services\Calendar\CalendarLayoutAssets;
use WBoost\Web\Services\Calendar\DescribeCalendarChange;

/**
 * The save algorithm of docs/calendar/architecture.md §C.5. Everything is
 * validated before anything is applied, so a refused request stores nothing.
 */
#[AsMessageHandler]
readonly final class SaveCalendarHandler
{
    /** History lines one request may add for content changes; the rest is summed up in one more. */
    public const int MAX_HISTORY_ENTRIES = 30;

    /** A design save right after another one by the same role is the same piece of work. */
    private const int DESIGN_ENTRY_COALESCE_SECONDS = 600;

    public function __construct(
        private CalendarRepository $calendarRepository,
        private CalendarAssetRepository $assetRepository,
        private CalendarHistoryRepository $historyRepository,
        private CalendarDesignRules $designRules,
        private CalendarContentRules $contentRules,
        private DescribeCalendarChange $describe,
        private CalendarHistoryWriter $history,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CalendarNotFound
     * @throws CalendarChangeForbidden
     * @throws InvalidCalendarChange
     */
    public function __invoke(SaveCalendar $message): void
    {
        $calendar = $this->calendarRepository->getForUpdate($message->calendarId);
        $actor = $message->actor;
        $now = $this->clock->now();

        // 1. Roles — a client's request is refused whole.
        if (!$actor->isDesigner() && ($message->designPatch !== null || $message->finish)) {
            throw new CalendarChangeForbidden();
        }

        foreach ($message->ops as $op) {
            $this->contentRules->assertWritable($op, $actor);
        }

        // 2. Design patch.
        $design = $message->designPatch !== null
            ? $this->designRules->validate($message->designPatch, $calendar->type, $calendar->layoutPreset)
            : [];

        // 3. Asset references of the layout and of every op, looked up at once.
        $layoutAssets = isset($design['layout']) ? CalendarLayoutAssets::ids($design['layout']) : [];
        $referenced = $layoutAssets;

        foreach ($message->ops as $op) {
            $referenced = [...$referenced, ...$this->contentRules->assetIds($op)];
        }

        $existingAssets = $this->assetRepository->existingIds($calendar->id, $referenced);

        foreach ($layoutAssets as $assetId) {
            if (!isset($existingAssets[$assetId])) {
                throw new InvalidCalendarChange('Layout odkazuje na soubor, který v kalendáři není nahraný.');
            }
        }

        // 4. Content ops, in order, against the working copy (dates follow
        //    the year the calendar has AFTER the patch).
        $year = $design['year'] ?? $calendar->year;
        $content = $calendar->content;
        $content['v'] = 1;
        $sentences = [];
        $contentChanged = false;

        foreach ($message->ops as $op) {
            $collection = is_array($content[$op->collection] ?? null) ? $content[$op->collection] : [];
            $stored = is_array($collection[$op->id] ?? null) ? $collection[$op->id] : null;

            $item = $this->contentRules->validate($op, $actor, $year, $existingAssets, $stored, $now);

            if (Calendar::same($stored, $item)) {
                continue;
            }

            if ($item === null) {
                unset($collection[$op->id]);
            } else {
                $collection[$op->id] = $item;
            }

            // The collection key is always kept, `{}` when empty.
            $content[$op->collection] = $collection;
            $contentChanged = true;

            $sentence = $this->describe->describe($op->collection, $stored, $item, $op->label, $content);

            if ($sentence !== null && !(DescribeCalendarChange::isCoalesced($sentence) && in_array($sentence, $sentences, true))) {
                $sentences[] = $sentence;
            }
        }

        if ($contentChanged) {
            if (strlen((string) json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > Calendar::MAX_CONTENT_BYTES) {
                throw new InvalidCalendarChange('Obsah kalendáře je příliš velký na uložení.');
            }

            $calendar->replaceContent($content);
        }

        // 5. Apply the design, then the history.
        $changedKeys = $calendar->applyDesign($design);

        if (array_diff($changedKeys, ['step']) !== [] && !$this->designEntryIsFresh($calendar, $message, $now)) {
            $this->history->record($calendar, $actor, DescribeCalendarChange::DESIGN_CHANGED, $now);
        }

        foreach (array_slice($sentences, 0, self::MAX_HISTORY_ENTRIES) as $sentence) {
            $this->history->record($calendar, $actor, $sentence, $now);
        }

        if (count($sentences) > self::MAX_HISTORY_ENTRIES) {
            $this->history->record($calendar, $actor, DescribeCalendarChange::andMore(count($sentences) - self::MAX_HISTORY_ENTRIES), $now);
        }

        if ($message->finish) {
            $this->history->record(
                $calendar,
                $actor,
                $calendar->finish() ? DescribeCalendarChange::FINISHED_FIRST : DescribeCalendarChange::FINISHED_AGAIN,
                $now,
            );
        }

        $calendar->touch($now);
    }

    /**
     * True when the newest history line already is "Upravil vzhled a
     * nastavení" by the same role, less than ten minutes old.
     */
    private function designEntryIsFresh(Calendar $calendar, SaveCalendar $message, \DateTimeImmutable $now): bool
    {
        $newest = $this->historyRepository->latest($calendar->id, 1)[0] ?? null;

        return $newest !== null
            && $newest->description === DescribeCalendarChange::DESIGN_CHANGED
            && $newest->authorKind === $message->actor->kind
            && $now->getTimestamp() - $newest->createdAt->getTimestamp() < self::DESIGN_ENTRY_COALESCE_SECONDS;
    }
}
