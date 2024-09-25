<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Font;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FontNotFound;
use WBoost\Web\Message\Font\DeleteFontFace;
use WBoost\Web\Repository\FontRepository;

#[AsMessageHandler]
readonly final class DeleteFontFaceHandler
{
    public function __construct(
        private FontRepository $fontRepository,
    ) {
    }

    /**
     * @throws FontNotFound
     */
    public function __invoke(DeleteFontFace $message): void
    {
        $font = $this->fontRepository->get($message->fontId);
        $font->removeFontFace($message->fontFaceName);
    }
}
