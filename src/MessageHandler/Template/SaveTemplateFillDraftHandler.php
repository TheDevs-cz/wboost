<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\Template\SaveTemplateFillDraft;
use WBoost\Web\Repository\TemplateExportVersionRepository;
use WBoost\Web\Value\ExportChannel;

#[AsMessageHandler]
readonly final class SaveTemplateFillDraftHandler
{
    public function __construct(
        private TemplateExportVersionRepository $versionRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SaveTemplateFillDraft $message): void
    {
        $user = $message->savedByUserId !== null
            ? $this->entityManager->find(User::class, $message->savedByUserId)
            : null;

        $existing = $this->versionRepository->find($message->versionId);

        if ($existing !== null) {
            // The controller only hands over an existing id when it is a draft
            // of this very subject; anything else is a programming error.
            if (!$existing->draft) {
                return;
            }

            $existing->saveDraft($message->fillValues, $this->clock->now(), $user);

            return;
        }

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
            $template = $this->entityManager->getRepository(Template::class)->findOneBy(['group' => $group]);
            if (!$template instanceof Template) {
                return;
            }
        }

        // Drafts share the export history's cap (pinned ones excepted).
        $this->versionRepository->prune($message->variantId, $message->groupId, RecordTemplateExportVersionHandler::MAX_VERSIONS - 1);

        $draft = new TemplateExportVersion(
            $message->versionId,
            $template,
            $variant,
            $group,
            $user,
            ExportChannel::Web,
            $message->fillValues,
            $message->fillValues->hash(),
            $this->clock->now(),
        );
        $draft->markDraft();

        $this->versionRepository->add($draft);
    }
}
