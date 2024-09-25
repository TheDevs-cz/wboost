<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualFontNotFound;
use WBoost\Web\Message\Manual\UpdateManualFontFaces;
use WBoost\Web\Repository\ManualFontRepository;

#[AsMessageHandler]
readonly final class UpdateManualFontFacesHandler
{
    public function __construct(
        private ManualFontRepository $manualFontRepository,
    ) {
    }

    /**
     * @throws ManualFontNotFound
     */
    public function __invoke(UpdateManualFontFaces $message): void
    {
        $manualFont = $this->manualFontRepository->get($message->manualFontId);
        $manualFont->enableFontFaces($message->fontFaces);
    }
}
