<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialNetwork;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\SocialNetworkTemplate;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Exceptions\SocialNetworkTemplateNotFound;
use WBoost\Web\Message\SocialNetwork\CopySocialNetworkTemplate;
use WBoost\Web\Repository\SocialNetworkTemplateRepository;
use WBoost\Web\Repository\SocialNetworkTemplateVariantRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class CopySocialNetworkTemplateHandler
{
    public function __construct(
        private SocialNetworkTemplateVariantRepository $variantRepository,
        private SocialNetworkTemplateRepository $templateRepository,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws SocialNetworkTemplateNotFound
     */
    public function __invoke(CopySocialNetworkTemplate $message): void
    {
        $originalTemplate = $this->templateRepository->get($message->originalTemplateId);
        $nextPosition = $this->templateRepository->count($originalTemplate->project->id);

        $newTemplate = new SocialNetworkTemplate(
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
            $newVariant = new SocialNetworkTemplateVariant(
                $this->provideIdentity->next(),
                $newTemplate,
                $originalVariant->dimension,
                $originalVariant->backgroundImage,
                $this->clock->now(),
            );

            $newVariant->editCanvas(
                $originalVariant->canvas,
                $originalVariant->inputs,
                $originalVariant->previewImage ?? $originalVariant->backgroundImage,
            );

            $this->variantRepository->add($newVariant);
        }
    }
}
