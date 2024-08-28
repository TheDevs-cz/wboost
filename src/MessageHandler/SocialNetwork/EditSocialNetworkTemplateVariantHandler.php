<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialNetwork;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\SocialNetworkTemplateVariantNotFound;
use WBoost\Web\Message\SocialNetwork\EditSocialNetworkTemplateVariant;
use WBoost\Web\Repository\SocialNetworkTemplateVariantRepository;

#[AsMessageHandler]
readonly final class EditSocialNetworkTemplateVariantHandler
{
    public function __construct(
        private SocialNetworkTemplateVariantRepository $variantRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws SocialNetworkTemplateVariantNotFound
     */
    public function __invoke(EditSocialNetworkTemplateVariant $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);

        $backgroundImagePath = $variant->backgroundImage;
        $backgroundImage = $message->backgroundImage;

        if ($backgroundImage !== null) {
            $timestamp = $this->clock->now()->getTimestamp();

            $extension = $backgroundImage->guessExtension();
            $backgroundImagePath = "social-networks/$variant->id/background-$timestamp.$extension";
            $this->filesystem->write($backgroundImagePath, $backgroundImage->getContent());
        }

        $variant->edit($backgroundImagePath);
    }
}
