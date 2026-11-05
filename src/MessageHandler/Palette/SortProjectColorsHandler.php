<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Palette;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Palette\SortProjectColors;
use WBoost\Web\Services\Palette\ProjectPaletteWriter;

#[AsMessageHandler]
readonly final class SortProjectColorsHandler
{
    public function __construct(
        private ProjectPaletteWriter $projectPaletteWriter,
    ) {
    }

    public function __invoke(SortProjectColors $message): void
    {
        // F7 P2: palette order only — manual colour order (and the JSONB mirror) is unaffected.
        $this->projectPaletteWriter->sortColors($message->projectId, $message->colorIds);
    }
}
