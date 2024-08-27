<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialNetwork;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Exceptions\SocialNetworkTemplateNotFound;
use WBoost\Web\Message\SocialNetwork\AddSocialNetworkTemplateVariant;
use WBoost\Web\Repository\SocialNetworkTemplateRepository;
use WBoost\Web\Repository\SocialNetworkTemplateVariantRepository;

#[AsMessageHandler]
readonly final class AddSocialNetworkTemplateVariantHandler
{
    public function __construct(
        private SocialNetworkTemplateRepository $templateRepository,
        private SocialNetworkTemplateVariantRepository $variantRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws SocialNetworkTemplateNotFound
     */
    public function __invoke(AddSocialNetworkTemplateVariant $message): void
    {
        $template = $this->templateRepository->get($message->templateId);
        $variantId = $message->variantId;
        $backgroundImage = $message->backgroundImage;

        // Maybe in future it will be possible to not have background when creating
        assert($backgroundImage !== null);

        $timestamp = $this->clock->now()->getTimestamp();

        $extension = $backgroundImage->guessExtension();
        $backgroundImagePath = "social-networks/$variantId/background-$timestamp.$extension";
        $this->filesystem->write($backgroundImagePath, $backgroundImage->getContent());

        $variant = new SocialNetworkTemplateVariant(
            $variantId,
            $template,
            $message->dimension,
            $backgroundImagePath,
            $this->clock->now(),
        );

        $this->variantRepository->add($variant);
    }
}
