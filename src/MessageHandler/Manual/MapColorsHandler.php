<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\MapColors;
use WBoost\Web\Repository\ManualRepository;

#[AsMessageHandler]
readonly final class MapColorsHandler
{
    public function __construct(
        private ManualRepository $manualRepository,
    ) {
    }

    /**
     * @throws ManualNotFound
     */
    public function __invoke(MapColors $message): void
    {
        $manual = $this->manualRepository->get($message->manualId);

        $manual->mapColors(
            $message->color1,
            $message->color2,
            $message->color3,
            $message->color4,
            $message->mapping,
        );
    }
}
