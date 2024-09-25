<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FontNotFound;
use WBoost\Web\Exceptions\ManualFontNotFound;
use WBoost\Web\Message\Manual\EditManualFont;
use WBoost\Web\Repository\FontRepository;
use WBoost\Web\Repository\ManualFontRepository;

#[AsMessageHandler]
readonly final class EditManualFontHandler
{
    public function __construct(
        private FontRepository $fontRepository,
        private ManualFontRepository $manualFontRepository,
    ) {
    }

    /**
     * @throws FontNotFound
     * @throws ManualFontNotFound
     */
    public function __invoke(EditManualFont $message): void
    {
        $font = $this->fontRepository->get($message->fontId);
        $manualFont = $this->manualFontRepository->get($message->manualFontId);

        $manualFont->edit(
            $font,
            $message->type,
            $message->color,
        );
    }
}
