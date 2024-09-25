<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Font;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FontNotFound;
use WBoost\Web\Message\Font\SortFontFaces;
use WBoost\Web\Repository\FontRepository;

#[AsMessageHandler]
readonly final class SortFontFacesHandler
{
    public function __construct(
        private FontRepository $fontRepository,
    ) {
    }


    /**
     * @throws FontNotFound
     */
    public function __invoke(SortFontFaces $message): void
    {
        $font = $this->fontRepository->get($message->fontId);
        $font->sortFaces($message->fontFaces);
    }
}
