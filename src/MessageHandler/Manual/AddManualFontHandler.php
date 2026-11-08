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
            // Every cut is on from the start: the manual preview skips a font
            // with no enabled cut, so an empty list made a freshly added font
            // invisible in the manual (2026-11-08). The designer switches cuts
            // off on the Fonty page.
            $font->faceNames(),
        );

        $this->manualFontRepository->add($manualFont);
    }
}
