<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\EditManualColors;
use WBoost\Web\Repository\ManualRepository;
use WBoost\Web\Services\Palette\ManualPaletteWriter;

#[AsMessageHandler]
readonly final class EditManualColorsHandler
{
    public function __construct(
        private ManualRepository $manualRepository,
        private ManualPaletteWriter $manualPaletteWriter,
    ) {
    }

    /**
     * @throws ManualNotFound
     */
    public function __invoke(EditManualColors $message): void
    {
        $manual = $this->manualRepository->get($message->manualId);

        // F7 R2: palette + links first, then the JSONB mirror of the project.
        $this->manualPaletteWriter->editColors(
            $manual->id,
            $message->detectedColors,
            $message->customColors,
        );
    }
}
