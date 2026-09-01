<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Template;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\Template\RecordTemplateExportVersion;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportFillValues;

/**
 * Single entry point the export chokepoints call — right next to
 * {@see \WBoost\Web\Services\Usage\RecordExportUsage} — to snapshot a
 * SUCCESSFUL export's fill values as a re-usable version.
 *
 * Same contract as usage tracking: versioning must NEVER break an export, so
 * every failure here is swallowed and logged, and recording happens after the
 * render succeeded.
 */
final readonly class RecordExportVersion
{
    public function __construct(
        private MessageBusInterface $bus,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    public function recordVariant(
        TemplateVariant $variant,
        ExportChannel $channel,
        ExportFillValues $fillValues,
    ): void {
        $this->record($variant->id, null, $channel, $fillValues, $variant->id->toString());
    }

    /**
     * One version per GROUP export — the ZIP and the per-dimension download
     * both snapshot the same group fill form, so they land on the same
     * subject (and deduplicate against each other).
     */
    public function recordGroup(
        TemplateGroup $group,
        ExportChannel $channel,
        ExportFillValues $fillValues,
    ): void {
        $this->record(null, $group->id, $channel, $fillValues, $group->id->toString());
    }

    private function record(
        null|UuidInterface $variantId,
        null|UuidInterface $groupId,
        ExportChannel $channel,
        ExportFillValues $fillValues,
        string $subjectId,
    ): void {
        try {
            $currentUser = $this->security->getUser();

            $this->bus->dispatch(new RecordTemplateExportVersion(
                $variantId,
                $groupId,
                $currentUser instanceof User ? $currentUser->id : null,
                $channel,
                $fillValues,
            ));
        } catch (Throwable $e) {
            $this->logger->error('Failed to record template export version.', [
                'exception' => $e,
                'subjectId' => $subjectId,
                'channel' => $channel->value,
            ]);
        }
    }
}
