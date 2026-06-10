<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\CustomTemplate;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\CustomTemplate;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Exceptions\CustomTemplateNotFound;
use WBoost\Web\Message\CustomTemplate\CopyCustomTemplate;
use WBoost\Web\Repository\CustomTemplateRepository;
use WBoost\Web\Repository\CustomTemplateVariantRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class CopyCustomTemplateHandler
{
    public function __construct(
        private CustomTemplateVariantRepository $variantRepository,
        private CustomTemplateRepository $templateRepository,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CustomTemplateNotFound
     */
    public function __invoke(CopyCustomTemplate $message): void
    {
        $originalTemplate = $this->templateRepository->get($message->originalTemplateId);
        $nextPosition = $this->templateRepository->count($originalTemplate->project->id);

        $newTemplate = new CustomTemplate(
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
            $newVariant = new CustomTemplateVariant(
                $this->provideIdentity->next(),
                $newTemplate,
                $originalVariant->dimension,
                $originalVariant->backgroundImage,
                $this->clock->now(),
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
