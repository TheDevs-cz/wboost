<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Palette;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualPaletteColorFromLogo;
use WBoost\Web\Exceptions\ManualPaletteColorNotFound;
use WBoost\Web\Message\Palette\RemoveManualPaletteColor;
use WBoost\Web\Services\Palette\ManualPaletteWriter;

#[AsMessageHandler]
readonly final class RemoveManualPaletteColorHandler
{
    public function __construct(
        private ManualPaletteWriter $manualPaletteWriter,
    ) {
    }

    /**
     * @throws ManualPaletteColorNotFound
     * @throws ManualPaletteColorFromLogo
     */
    public function __invoke(RemoveManualPaletteColor $message): void
    {
        $this->manualPaletteWriter->removeColor($message->manualId, $message->linkId);
    }
}
