<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\EditManualPageText;
use WBoost\Web\Repository\ManualRepository;

#[AsMessageHandler]
readonly final class EditManualPageTextHandler
{
    public function __construct(
        private ManualRepository $manualRepository,
    ) {
    }

    /**
     * @throws ManualNotFound
     */
    public function __invoke(EditManualPageText $message): void
    {
        $manual = $this->manualRepository->get($message->manualId);

        $manual->editPageText($message->page, $message->title, $message->description);
    }
}
