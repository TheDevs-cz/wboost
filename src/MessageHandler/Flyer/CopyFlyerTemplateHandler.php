<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Flyer;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\FlyerTemplate;
use WBoost\Web\Entity\FlyerTemplateVariant;
use WBoost\Web\Exceptions\FlyerTemplateNotFound;
use WBoost\Web\Message\Flyer\CopyFlyerTemplate;
use WBoost\Web\Repository\FlyerTemplateRepository;
use WBoost\Web\Repository\FlyerTemplateVariantRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class CopyFlyerTemplateHandler
{
    public function __construct(
        private FlyerTemplateVariantRepository $variantRepository,
        private FlyerTemplateRepository $templateRepository,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws FlyerTemplateNotFound
     */
    public function __invoke(CopyFlyerTemplate $message): void
    {
        $originalTemplate = $this->templateRepository->get($message->originalTemplateId);
        $nextPosition = $this->templateRepository->count($originalTemplate->project->id);

        $newTemplate = new FlyerTemplate(
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
            $newVariant = new FlyerTemplateVariant(
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
