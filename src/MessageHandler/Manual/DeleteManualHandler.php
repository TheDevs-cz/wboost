<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\DeleteManual;
use WBoost\Web\Repository\ManualRepository;

#[AsMessageHandler]
readonly final class DeleteManualHandler
{
    public function __construct(
        private ManualRepository $manualRepository,
    ) {
    }

    /**
     * @throws ManualNotFound
     */
    public function __invoke(DeleteManual $message): void
    {
        $manual = $this->manualRepository->get($message->manualId);

        $this->manualRepository->remove($manual);
    }
}
