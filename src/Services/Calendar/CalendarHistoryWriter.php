<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use DateTimeImmutable;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Entity\CalendarHistoryEntry;
use WBoost\Web\Entity\User;
use WBoost\Web\Repository\CalendarHistoryRepository;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Value\Calendar\CalendarActor;

/**
 * Adds history lines for the calendar handlers (history is recorded
 * explicitly, never through lifecycle events). Runs inside the caller's
 * transaction.
 */
readonly final class CalendarHistoryWriter
{
    /**
     * History lines kept per calendar — ten times what Historie shows
     * ({@see CalendarHistoryList::LIMIT}). The public client link can add up
     * to 32 lines per save, so without a bound the table grows as fast as
     * somebody cares to save.
     */
    public const int MAX_ENTRIES = 2000;

    public function __construct(
        private CalendarHistoryRepository $historyRepository,
        private ProvideIdentity $provideIdentity,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function record(Calendar $calendar, CalendarActor $actor, string $description, DateTimeImmutable $now): void
    {
        if (mb_strlen($description) > CalendarHistoryEntry::MAX_DESCRIPTION_LENGTH) {
            $description = mb_substr($description, 0, CalendarHistoryEntry::MAX_DESCRIPTION_LENGTH - 1) . '…';
        }

        $this->historyRepository->add(new CalendarHistoryEntry(
            $this->provideIdentity->next(),
            $calendar,
            $actor->kind,
            $this->user($actor),
            $description,
            $now,
        ));
    }

    /**
     * Drops everything but the newest {@see MAX_ENTRIES} lines of the
     * calendar. Called once by a handler that recorded something, after its
     * last {@see record()}: the pending lines are flushed first (still inside
     * the handler's transaction) so they count as the newest.
     */
    public function prune(Calendar $calendar): void
    {
        $this->entityManager->flush();

        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM calendar_history
             WHERE calendar_id = :calendarId
               AND id IN (
                   SELECT id FROM calendar_history
                   WHERE calendar_id = :calendarId
                   ORDER BY created_at DESC, id DESC
                   OFFSET :keep
               )',
            ['calendarId' => $calendar->id->toString(), 'keep' => self::MAX_ENTRIES],
            ['keep' => ParameterType::INTEGER],
        );
    }

    public function user(CalendarActor $actor): null|User
    {
        return $actor->userId !== null ? $this->entityManager->find(User::class, $actor->userId) : null;
    }
}
