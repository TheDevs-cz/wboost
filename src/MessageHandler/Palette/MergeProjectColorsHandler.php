<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Palette;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ProjectColorNotFound;
use WBoost\Web\Exceptions\ProjectColorsNotDuplicates;
use WBoost\Web\Message\Palette\MergeProjectColors;
use WBoost\Web\Services\Palette\ProjectPaletteWriter;

#[AsMessageHandler]
readonly final class MergeProjectColorsHandler
{
    public function __construct(
        private ProjectPaletteWriter $projectPaletteWriter,
    ) {
    }

    /**
     * @throws ProjectColorNotFound
     * @throws ProjectColorsNotDuplicates
     */
    public function __invoke(MergeProjectColors $message): void
    {
        // F7 P4: palette rows + links first, then the JSONB mirror of the project.
        $this->projectPaletteWriter->mergeColors($message->survivorId, $message->loserIds);
    }
}
