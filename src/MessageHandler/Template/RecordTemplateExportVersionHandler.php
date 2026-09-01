<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\Template\RecordTemplateExportVersion;
use WBoost\Web\Repository\TemplateExportVersionRepository;

#[AsMessageHandler]
readonly final class RecordTemplateExportVersionHandler
{
    /**
     * History cap per fill surface (variant or group): enough to hold weeks of
     * distinct fills, small enough that a busy template can't grow unbounded.
     */
    public const int MAX_VERSIONS = 30;

    public function __construct(
        private TemplateExportVersionRepository $versionRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordTemplateExportVersion $message): void
    {
        $variant = null;
        $group = null;

        if ($message->variantId !== null) {
            $variant = $this->entityManager->find(TemplateVariant::class, $message->variantId);
            if (!$variant instanceof TemplateVariant) {
                return;
            }
            $template = $variant->template;
        } else {
            $group = $this->entityManager->find(TemplateGroup::class, $message->groupId);
            if (!$group instanceof TemplateGroup) {
                return;
            }
            // A group and its template are 1:1 (membership = nullable FK on
            // the template) — a group without one has no fill surface left.
            $template = $this->entityManager->getRepository(Template::class)->findOneBy(['group' => $group]);
            if (!$template instanceof Template) {
                return;
            }
        }

        $user = $message->exportedByUserId !== null
            ? $this->entityManager->find(User::class, $message->exportedByUserId)
            : null;

        $hash = $message->fillValues->hash();

        $duplicate = $this->versionRepository->findDuplicate($message->variantId, $message->groupId, $hash);

        if ($duplicate !== null) {
            $duplicate->bump($this->clock->now(), $user, $message->channel);

            return;
        }

        // Prune BEFORE adding (the new row is not flushed yet, so it cannot be
        // seen — keep cap-1 existing rows and the insert lands at the cap).
        $this->versionRepository->prune($message->variantId, $message->groupId, self::MAX_VERSIONS - 1);

        $this->versionRepository->add(new TemplateExportVersion(
            Uuid::uuid7(),
            $template,
            $variant,
            $group,
            $user,
            $message->channel,
            $message->fillValues,
            $hash,
            $this->clock->now(),
        ));
    }
}
