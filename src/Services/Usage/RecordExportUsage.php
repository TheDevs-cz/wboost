<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Usage;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\Usage\RecordTemplateExport;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportedTemplateType;

/**
 * Single entry point the four export chokepoints (the two web download
 * controllers and the two API export processors) call to track a successful
 * export.
 *
 * Tracking must NEVER break a user's export: every failure here is swallowed
 * and logged. Recording is always the last step before the PNG response is
 * returned, so a rolled-back tracking transaction cannot affect anything else
 * in the request.
 */
final readonly class RecordExportUsage
{
    public function __construct(
        private MessageBusInterface $bus,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    public function record(
        SocialNetworkTemplateVariant|CustomTemplateVariant $variant,
        ExportChannel $channel,
    ): void {
        try {
            $templateType = $variant instanceof SocialNetworkTemplateVariant
                ? ExportedTemplateType::SocialNetwork
                : ExportedTemplateType::CustomTemplate;

            $template = $variant->template;
            $project = $template->project;
            $owner = $project->owner;

            $currentUser = $this->security->getUser();

            $this->bus->dispatch(new RecordTemplateExport(
                $templateType,
                $channel,
                $template->id,
                $template->name,
                $variant->id,
                $project->id,
                $project->name,
                $owner->id,
                $owner->email,
                $currentUser instanceof User ? $currentUser->id : null,
            ));
        } catch (Throwable $e) {
            $this->logger->error('Failed to record template export usage.', [
                'exception' => $e,
                'variantId' => $variant->id->toString(),
                'channel' => $channel->value,
            ]);
        }
    }
}
