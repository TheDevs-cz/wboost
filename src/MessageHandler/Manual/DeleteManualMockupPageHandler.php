<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualMockupPageNotFound;
use WBoost\Web\Message\Manual\DeleteManualMockupPage;
use WBoost\Web\Repository\ManualMockupPageRepository;

#[AsMessageHandler]
readonly final class DeleteManualMockupPageHandler
{
    public function __construct(
        private ManualMockupPageRepository $manualMockupPageRepository,
    ) {
    }

    /**
     * @throws ManualMockupPageNotFound
     */
    public function __invoke(DeleteManualMockupPage $message): void
    {
        $page = $this->manualMockupPageRepository->get($message->pageId);

        $this->manualMockupPageRepository->remove($page);
    }
}
