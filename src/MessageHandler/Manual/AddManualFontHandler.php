<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\ManualFont;
use WBoost\Web\Message\Manual\AddManualFont;
use WBoost\Web\Repository\FontRepository;
use WBoost\Web\Repository\ManualFontRepository;
use WBoost\Web\Repository\ManualRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class AddManualFontHandler
{
    public function __construct(
        private ManualRepository $manualRepository,
        private FontRepository $fontRepository,
        private ManualFontRepository $manualFontRepository,
        private ClockInterface $clock,
        private ProvideIdentity $provideIdentity,
    ) {
    }

    public function __invoke(AddManualFont $message): void
    {
        $manual = $this->manualRepository->get($message->manualId);
        $font = $this->fontRepository->get($message->fontId);
        $nextPosition = $this->manualFontRepository->count($message->manualId);

        $manualFont = new ManualFont(
            $this->provideIdentity->next(),
            $manual,
            $font,
            $message->type,
            $message->color,
            $nextPosition,
            $this->clock->now(),
        );

        $this->manualFontRepository->add($manualFont);
    }
}
