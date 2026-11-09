<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\InvalidManualContent;
use WBoost\Web\Exceptions\ManualContentPageNotFound;
use WBoost\Web\Message\Manual\RenameManualContentPage;
use WBoost\Web\Repository\ManualContentPageRepository;
use WBoost\Web\Value\ManualContent\ManualContentPagePayload;

#[AsMessageHandler]
readonly final class RenameManualContentPageHandler
{
    public function __construct(
        private ManualContentPageRepository $contentPageRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ManualContentPageNotFound
     * @throws InvalidManualContent
     */
    public function __invoke(RenameManualContentPage $message): void
    {
        $page = $this->contentPageRepository->get($message->pageId);

        $page->rename(ManualContentPagePayload::name($message->name), $this->clock->now());
    }
}
