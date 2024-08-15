<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\ManualMockupPage;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\AddManualMockupPage;
use WBoost\Web\Repository\ManualMockupPageRepository;
use WBoost\Web\Repository\ManualRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class AddManualMockupPageHandler
{
    public function __construct(
        private ManualRepository $manualRepository,
        private ManualMockupPageRepository $manualMockupPageRepository,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws ManualNotFound
     */
    public function __invoke(AddManualMockupPage $message): void
    {
        $manual = $this->manualRepository->get($message->manualId);
        $pageId = $this->provideIdentity->next();
        $timestamp = $this->clock->now()->getTimestamp();
        $images = [];

        foreach ($message->images as $index => $image) {
            if ($image === null) {
                $images[] = null;
                continue;
            }

            $imageNumber = $index + 1;

            $extension = $image->guessExtension();
            $path = "manuals/$manual->id/pages/$pageId/image-$imageNumber-$timestamp.$extension";

            $this->filesystem->write($path, $image->getContent());

            $images[] = $path;
        }

        $page = new ManualMockupPage(
            $pageId,
            $manual,
            $this->clock->now(),
            $message->layout,
            $message->name,
            $images,
        );

        $this->manualMockupPageRepository->add($page);
    }
}
