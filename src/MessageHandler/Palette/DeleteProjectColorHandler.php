<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Palette;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ProjectColorInLogo;
use WBoost\Web\Exceptions\ProjectColorNotFound;
use WBoost\Web\Exceptions\ProjectColorUsedInManuals;
use WBoost\Web\Message\Palette\DeleteProjectColor;
use WBoost\Web\Services\Palette\ProjectPaletteWriter;

#[AsMessageHandler]
readonly final class DeleteProjectColorHandler
{
    public function __construct(
        private ProjectPaletteWriter $projectPaletteWriter,
    ) {
    }

    /**
     * @throws ProjectColorNotFound
     * @throws ProjectColorInLogo
     * @throws ProjectColorUsedInManuals
     */
    public function __invoke(DeleteProjectColor $message): void
    {
        // F7 P2: the palette row and (with the confirmation) its links.
        $this->projectPaletteWriter->deleteColor($message->colorId, $message->removeFromManuals);
    }
}
