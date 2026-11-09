<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\EnsureManualGalleryFolder;
use WBoost\Web\Repository\ManualRepository;
use WBoost\Web\Services\Manual\ManualGalleryFolders;

#[AsMessageHandler]
readonly final class EnsureManualGalleryFolderHandler
{
    public function __construct(
        private ManualRepository $manualRepository,
        private ManualGalleryFolders $folders,
    ) {
    }

    /**
     * @throws ManualNotFound
     */
    public function __invoke(EnsureManualGalleryFolder $message): void
    {
        $this->folders->folderFor($this->manualRepository->get($message->manualId));
    }
}
