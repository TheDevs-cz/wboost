<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use DateTimeImmutable;
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

    public function user(CalendarActor $actor): null|User
    {
        return $actor->userId !== null ? $this->entityManager->find(User::class, $actor->userId) : null;
    }
}
