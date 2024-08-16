<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualMockupPageNotFound;
use WBoost\Web\Message\Manual\EditManualMockupPage;
use WBoost\Web\Repository\ManualMockupPageRepository;

#[AsMessageHandler]
readonly final class EditManualMockupPageHandler
{
    public function __construct(
        private ManualMockupPageRepository $manualMockupPageRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws ManualMockupPageNotFound
     */
    public function __invoke(EditManualMockupPage $message): void
    {
        $page = $this->manualMockupPageRepository->get($message->pageId);
        $timestamp = $this->clock->now()->getTimestamp();
        $images = [];

        foreach ($message->images as $index => $image) {
            if ($image === null) {
                // Index should always exist, if not - something is wrong and should be error
                $images[] = $page->images[$index];
                continue;
            }

            $imageNumber = $index + 1;
            $manualId = $page->manual->id;
            $extension = $image->guessExtension();
            $path = "manuals/$manualId/pages/$page->id/image-$imageNumber-$timestamp.$extension";

            $this->filesystem->write($path, $image->getContent());

            $images[] = $path;
        }

        $page->edit(
            $message->name,
            $images,
        );
    }
}
