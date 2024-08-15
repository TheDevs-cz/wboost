<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Manual\EditManualMockupPage;

#[AsMessageHandler]
readonly final class EditManualMockupPageHandler
{
    public function __invoke(EditManualMockupPage $message): void
    {
    }
}
