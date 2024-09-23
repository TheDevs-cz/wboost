<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\EditManualLogoColorsMapping;
use WBoost\Web\Repository\ManualRepository;

#[AsMessageHandler]
readonly final class EditManualLogoColorsMappingHandler
{
    public function __construct(
        private ManualRepository $manualRepository,
    ) {
    }

    /**
     * @throws ManualNotFound
     */
    public function __invoke(EditManualLogoColorsMapping $message): void
    {
        $manual = $this->manualRepository->get($message->manualId);

        $manual->updateColorMapping(
            $message->logoTypeVariant,
            $message->logoColorVariant,
            $message->background,
            $message->mapping,
        );
    }
}
