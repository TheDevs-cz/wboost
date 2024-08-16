<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Font;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Font\DeleteFont;
use WBoost\Web\Repository\FontRepository;

#[AsMessageHandler]
readonly final class DeleteFontHandler
{
    public function __construct(
        private FontRepository $fontRepository,
    ) {
    }

    public function __invoke(DeleteFont $message): void
    {
        $font = $this->fontRepository->get($message->fontId);

        $this->fontRepository->remove($font);
    }
}
