<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Template\PinTemplateExportVersion;
use WBoost\Web\Repository\TemplateExportVersionRepository;

#[AsMessageHandler]
readonly final class PinTemplateExportVersionHandler
{
    public function __construct(
        private TemplateExportVersionRepository $versionRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PinTemplateExportVersion $message): void
    {
        $version = $this->versionRepository->get($message->versionId);

        if ($message->pinned) {
            $version->pin($this->clock->now());
        } else {
            $version->unpin();
        }
    }
}
