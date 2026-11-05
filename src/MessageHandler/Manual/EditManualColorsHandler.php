<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\EditManualColors;
use WBoost\Web\Repository\ManualRepository;
use WBoost\Web\Services\Palette\ProjectManualColors;

#[AsMessageHandler]
readonly final class EditManualColorsHandler
{
    public function __construct(
        private ManualRepository $manualRepository,
        private EntityManagerInterface $entityManager,
        private ProjectManualColors $projectManualColors,
    ) {
    }

    /**
     * @throws ManualNotFound
     */
    public function __invoke(EditManualColors $message): void
    {
        $manual = $this->manualRepository->get($message->manualId);
        $rolesBefore = $this->projectManualColors->rolesOf($manual->id);

        $manual->editColors(
            $message->detectedColors,
            $message->customColors,
        );

        // Palette R1 sync (F7): the projector reads the STORED columns.
        $this->entityManager->flush();
        $this->projectManualColors->reproject($manual->id, $rolesBefore);
    }
}
