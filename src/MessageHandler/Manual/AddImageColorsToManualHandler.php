<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\AddImageColorsToManual;
use WBoost\Web\Repository\ManualRepository;
use WBoost\Web\Services\DetectImageColors;
use WBoost\Web\Services\UploaderHelper;

#[AsMessageHandler]
readonly final class AddImageColorsToManualHandler
{
    public function __construct(
        private DetectImageColors $detectImageColors,
        private ManualRepository $manualRepository,
        private UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * @throws ManualNotFound
     */
    public function __invoke(AddImageColorsToManual $message): void
    {
        $manual = $this->manualRepository->get($message->manualId);

        $colors = $this->detectImageColors->fromImagePath(
            $this->uploaderHelper->getInternalPath($message->imagePath),
        );

        $manual->addColors($colors);
    }
}
