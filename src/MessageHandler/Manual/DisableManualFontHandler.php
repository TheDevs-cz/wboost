<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Manual\DisableManualFont;
use WBoost\Web\Repository\FontRepository;
use WBoost\Web\Repository\ManualRepository;

#[AsMessageHandler]
readonly final class DisableManualFontHandler
{
    public function __construct(
        private FontRepository $fontRepository,
        private ManualRepository $manualRepository,
    ) {
    }

    public function __invoke(DisableManualFont $message): void
    {
        $manual = $this->manualRepository->get($message->manualId);
        $font = $this->fontRepository->get($message->fontId);

        $manual->disableFont($font);
    }
}
