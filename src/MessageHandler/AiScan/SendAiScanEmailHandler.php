<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\AiScan;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\AiScan\SendAiScanEmail;
use WBoost\Web\Services\AiScan\AiScanNotifications;

#[AsMessageHandler]
readonly final class SendAiScanEmailHandler
{
    public function __construct(
        private AiScanNotifications $notifications,
    ) {
    }

    public function __invoke(SendAiScanEmail $message): void
    {
        if ($message->reminder) {
            $this->notifications->sendReminder($message->scanId);

            return;
        }

        $this->notifications->sendEnded($message->scanId);
    }
}
