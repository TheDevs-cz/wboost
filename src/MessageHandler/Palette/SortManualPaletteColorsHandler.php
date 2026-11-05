<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Palette;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Palette\SortManualPaletteColors;
use WBoost\Web\Services\Palette\ManualPaletteWriter;

#[AsMessageHandler]
readonly final class SortManualPaletteColorsHandler
{
    public function __construct(
        private ManualPaletteWriter $manualPaletteWriter,
    ) {
    }

    public function __invoke(SortManualPaletteColors $message): void
    {
        $this->manualPaletteWriter->sortColors($message->manualId, $message->linkIds);
    }
}
