<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Palette;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Palette\AddProjectColor;
use WBoost\Web\Services\Palette\ProjectPaletteWriter;

#[AsMessageHandler]
readonly final class AddProjectColorHandler
{
    public function __construct(
        private ProjectPaletteWriter $projectPaletteWriter,
    ) {
    }

    public function __invoke(AddProjectColor $message): void
    {
        // F7 P2: an unlinked colour — no manual, so no JSONB mirror to rewrite.
        $this->projectPaletteWriter->addColor($message->colorId, $message->projectId, $message->hex);
    }
}
