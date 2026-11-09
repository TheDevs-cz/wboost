<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\EditManual;
use WBoost\Web\Repository\ManualRepository;
use WBoost\Web\Services\Manual\IntroContentPageImage;

#[AsMessageHandler]
readonly final class EditManualHandler
{
    public function __construct(
        private ManualRepository $manualRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
        private IntroContentPageImage $introContentPageImage,
    ) {
    }

    /**
     * @throws ManualNotFound
     */
    public function __invoke(EditManual $message): void
    {
        $manual = $this->manualRepository->get($message->manualId);
        $introImagePath = $manual->introImage;

        if ($message->introImage !== null) {
            $timestamp = $this->clock->now()->getTimestamp();

            $extension = $message->introImage->guessExtension();
            $introImagePath = "manuals/$message->manualId/intro-$timestamp.$extension";

            $fileContent = $message->introImage->getContent();
            $this->filesystem->write($introImagePath, $fileContent);
        }

        $previousIntroImage = $manual->introImage;

        $manual->edit(
            $message->type,
            $message->name,
            $introImagePath,
        );

        // A new upload lands in the intro content page, which is what the
        // manual renders (P1); the column itself goes away in P4.
        if ($introImagePath !== null && $introImagePath !== $previousIntroImage) {
            $this->introContentPageImage->apply($manual, $previousIntroImage, $introImagePath);
        }
    }
}
