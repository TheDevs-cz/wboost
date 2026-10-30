<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\TemplateNotFound;
use WBoost\Web\Message\Template\CopyTemplate;
use WBoost\Web\Repository\TemplateGroupRepository;
use WBoost\Web\Repository\TemplateRepository;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\ProvideIdentity;

/**
 * A deep, unaffiliated clone: nothing links the copy back to the original.
 * Storage keys (background, card picture, gallery picks inside the canvas) are
 * shared rather than re-uploaded — no template/variant delete ever removes
 * storage, and the per-variant preview key is derived from the NEW variant id,
 * so the first save of the copy writes its own thumbnail.
 *
 * A synchronized template is copied as a synchronized template of its OWN: a
 * brand-new group, joined by exactly the variants that were group members in
 * the original (manually added variants stay outside, as in the source).
 */
#[AsMessageHandler]
readonly final class CopyTemplateHandler
{
    public function __construct(
        private TemplateVariantRepository $variantRepository,
        private TemplateRepository $templateRepository,
        private TemplateGroupRepository $templateGroupRepository,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws TemplateNotFound
     */
    public function __invoke(CopyTemplate $message): void
    {
        $originalTemplate = $this->templateRepository->get($message->originalTemplateId);
        $nextPosition = $this->templateRepository->nextPosition($originalTemplate->project->id);

        $newTemplate = new Template(
            $message->newTemplateId,
            $originalTemplate->project,
            $originalTemplate->category,
            $this->clock->now(),
            $originalTemplate->name . ' (kopie)',
            $originalTemplate->image,
            $nextPosition,
        );

        $newTemplate->arrangeFillFields($originalTemplate->fillFieldOrder);

        $newGroup = null;

        if ($originalTemplate->group !== null) {
            // Group and template are born with ONE name (see Template::edit()).
            $newGroup = new TemplateGroup(
                $this->provideIdentity->next(),
                $originalTemplate->project,
                $this->clock->now(),
                $newTemplate->name,
            );
            $this->templateGroupRepository->add($newGroup);
            $newTemplate->assignToGroup($newGroup);
        }

        $this->templateRepository->add($newTemplate);

        foreach ($originalTemplate->variants() as $originalVariant) {
            $newVariant = new TemplateVariant(
                $this->provideIdentity->next(),
                $newTemplate,
                $originalVariant->dimension,
                $originalVariant->backgroundImage,
                $this->clock->now(),
                $originalVariant->backgroundMode,
            );

            $newVariant->editCanvas(
                $originalVariant->canvas,
                $originalVariant->inputs,
                $originalVariant->previewImagePath,
                $originalVariant->imageInputs,
            );

            if ($newGroup !== null && $originalVariant->group !== null) {
                $newVariant->assignToGroup($newGroup);
            }

            $this->variantRepository->add($newVariant);
        }
    }
}
