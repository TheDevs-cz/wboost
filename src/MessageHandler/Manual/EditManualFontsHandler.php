<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FontNotFound;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\EditManualFonts;
use WBoost\Web\Repository\FontRepository;
use WBoost\Web\Repository\ManualRepository;

#[AsMessageHandler]
readonly final class EditManualFontsHandler
{
    public function __construct(
        private FontRepository $fontRepository,
        private ManualRepository $manualRepository,
    ) {
    }

    /**
     * @throws ManualNotFound
     * @throws FontNotFound
     */
    public function __invoke(EditManualFonts $message): void
    {
        $manual = $this->manualRepository->get($message->manualId);

        $primaryFont = null;
        $secondaryFont = null;

        if ($message->primaryFontId !== null) {
            $primaryFont = $this->fontRepository->get($message->primaryFontId);
        }

        if ($message->secondaryFontId !== null) {
            $secondaryFont = $this->fontRepository->get($message->secondaryFontId);
        }

        $manual->editFonts(
            $primaryFont,
            $secondaryFont,
        );
    }
}
