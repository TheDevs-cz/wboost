<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Palette;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ProjectColorHexLocked;
use WBoost\Web\Exceptions\ProjectColorNotFound;
use WBoost\Web\Message\Palette\EditProjectColor;
use WBoost\Web\Services\Palette\ProjectPaletteWriter;

#[AsMessageHandler]
readonly final class EditProjectColorHandler
{
    public function __construct(
        private ProjectPaletteWriter $projectPaletteWriter,
    ) {
    }

    /**
     * @throws ProjectColorNotFound
     * @throws ProjectColorHexLocked
     */
    public function __invoke(EditProjectColor $message): void
    {
        // F7 P2: palette rows first, then the JSONB mirror of the project.
        $this->projectPaletteWriter->editColor($message->colorId, $message->field, $message->value, $message->cmyk);
    }
}
