<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Calendar;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\CalendarNotFound;
use WBoost\Web\Message\Calendar\DeleteCalendar;
use WBoost\Web\Repository\CalendarRepository;

#[AsMessageHandler]
readonly final class DeleteCalendarHandler
{
    public function __construct(
        private CalendarRepository $calendarRepository,
        private EntityManagerInterface $entityManager,
        private Filesystem $filesystem,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Same order as deleting a project: remember the directory, remove +
     * FLUSH (assets and history cascade; a constraint failure surfaces before
     * a byte is touched), only then delete the files. A storage failure is
     * logged, not rethrown — the row is gone and the storage report shows
     * what was left.
     *
     * @throws CalendarNotFound
     */
    public function __invoke(DeleteCalendar $message): void
    {
        $calendar = $this->calendarRepository->get($message->calendarId);
        $directory = sprintf('calendars/%s', $calendar->id->toString());

        $this->calendarRepository->remove($calendar);
        $this->entityManager->flush();

        try {
            $this->filesystem->deleteDirectory($directory);
        } catch (FilesystemException $error) {
            $this->logger->error('Failed to delete calendar storage directory.', [
                'directory' => $directory,
                'exception' => $error,
            ]);
        }
    }
}
