<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\TemplateNotFound;
use WBoost\Web\Message\Template\CopyTemplate;
use WBoost\Web\Repository\TemplateRepository;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class CopyTemplateHandler
{
    public function __construct(
        private TemplateVariantRepository $variantRepository,
        private TemplateRepository $templateRepository,
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
        $nextPosition = $this->templateRepository->count($originalTemplate->project->id);

        $newTemplate = new Template(
            $message->newTemplateId,
            $originalTemplate->project,
            $originalTemplate->category,
            $this->clock->now(),
            $originalTemplate->name . ' (kopie)',
            $originalTemplate->image,
            $nextPosition,
        );

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

            $this->variantRepository->add($newVariant);
        }
    }
}
