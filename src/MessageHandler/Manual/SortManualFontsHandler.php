<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Manual\SortManualFonts;
use WBoost\Web\Repository\ManualFontRepository;

#[AsMessageHandler]
readonly final class SortManualFontsHandler
{
    public function __construct(
        private ManualFontRepository $manualFontRepository
    ) {
    }

    public function __invoke(SortManualFonts $message): void
    {
        foreach ($message->manualFonts as $position => $manualFontId) {
            $manualFont = $this->manualFontRepository->get(Uuid::fromString($manualFontId));
            $manualFont->sort($position);
        }
    }
}
