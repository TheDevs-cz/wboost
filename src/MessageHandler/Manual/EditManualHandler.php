<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\EditManual;
use WBoost\Web\Repository\ManualRepository;

#[AsMessageHandler]
readonly final class EditManualHandler
{
    public function __construct(
        private ManualRepository $manualRepository,
    ) {
    }

    /**
     * @throws ManualNotFound
     */
    public function __invoke(EditManual $message): void
    {
        $manual = $this->manualRepository->get($message->manualId);

        $manual->edit(
            $message->type,
            $message->name,
        );
    }
}
