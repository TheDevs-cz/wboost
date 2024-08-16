<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\EditManualColors;
use WBoost\Web\Repository\ManualRepository;

#[AsMessageHandler]
readonly final class EditManualColorsHandler
{
    public function __construct(
        private ManualRepository $manualRepository,
    ) {
    }

    /**
     * @throws ManualNotFound
     */
    public function __invoke(EditManualColors $message): void
    {
        $manual = $this->manualRepository->get($message->manualId);

        $manual->editColors(
            $message->color1,
            $message->color2,
            $message->color3,
            $message->color4,
            $message->mapping,
            $message->secondaryColors,
        );
    }
}
