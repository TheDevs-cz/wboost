<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Manual\DeleteManualFont;
use WBoost\Web\Repository\ManualFontRepository;

#[AsMessageHandler]
readonly final class DeleteManualFontHandler
{
    public function __construct(
        private ManualFontRepository $manualFontRepository,
    )
    {
    }

    public function __invoke(DeleteManualFont $message): void
    {
        $manualFont = $this->manualFontRepository->get($message->manualFontId);

        $this->manualFontRepository->remove($manualFont);
    }
}
