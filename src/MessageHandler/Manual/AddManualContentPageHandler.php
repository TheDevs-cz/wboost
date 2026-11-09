<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Exceptions\InvalidManualContent;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\AddManualContentPage;
use WBoost\Web\Repository\ManualContentPageRepository;
use WBoost\Web\Repository\ManualRepository;
use WBoost\Web\Services\Manual\ManualPagePositions;
use WBoost\Web\Value\ManualContent\ManualContentPagePayload;
use WBoost\Web\Value\ManualContent\ManualContentPageRole;

#[AsMessageHandler]
readonly final class AddManualContentPageHandler
{
    public function __construct(
        private ManualRepository $manualRepository,
        private ManualContentPageRepository $contentPageRepository,
        private ManualPagePositions $positions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ManualNotFound
     * @throws InvalidManualContent
     */
    public function __invoke(AddManualContentPage $message): void
    {
        $manual = $this->manualRepository->get($message->manualId);

        $this->contentPageRepository->add(new ManualContentPage(
            $message->pageId,
            $manual,
            ManualContentPageRole::Custom,
            ManualContentPagePayload::name($message->name),
            $message->layout,
            [],
            $this->positions->next($manual->id),
            $this->clock->now(),
        ));
    }
}
