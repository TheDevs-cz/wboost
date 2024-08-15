<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Manual\DeleteManualMockupPage;

#[AsMessageHandler]
readonly final class DeleteManualMockupPageHandler
{
    public function __invoke(DeleteManualMockupPage $message): void
    {

    }
}
