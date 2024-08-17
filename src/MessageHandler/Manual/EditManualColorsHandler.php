<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\EditManualColors;
use WBoost\Web\Repository\ManualRepository;
use WBoost\Web\Value\ColorMapping;

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

        $mapping = [];

        foreach ($message->mapping as $hex => $primaryColorNumber) {
            if ($primaryColorNumber === '') {
                continue;
            }

            $mapping[] = new ColorMapping($hex, (int) $primaryColorNumber);
        }

        $manual->editColors(
            $message->primaryColors,
            $message->secondaryColors,
            $mapping,
        );
    }
}
