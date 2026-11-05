<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Palette;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Palette\AddManualPaletteColors;
use WBoost\Web\Services\Palette\ManualPaletteWriter;

#[AsMessageHandler]
readonly final class AddManualPaletteColorsHandler
{
    public function __construct(
        private ManualPaletteWriter $manualPaletteWriter,
    ) {
    }

    public function __invoke(AddManualPaletteColors $message): void
    {
        // F7 P3: links first, then the JSONB mirror of the project (R2).
        $this->manualPaletteWriter->addColors($message->manualId, $message->colorIds);
    }
}
