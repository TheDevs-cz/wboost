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
            // Legacy raw-upload path: store the file alongside the variant.
            $timestamp = $this->clock->now()->getTimestamp();

            $extension = $backgroundImage->guessExtension();
            $backgroundImagePath = "social-networks/$variant->id/background-$timestamp.$extension";
            $this->filesystem->write($backgroundImagePath, $backgroundImage->getContent());
        } elseif ($message->backgroundImagePath !== null) {
            // Stage 7 gallery path: the asset already lives in S3/Minio under
            // file-upload/{projectId}/{fileId}.{ext} as a FileUpload row; just
            // point the variant at it.
            $backgroundImagePath = $message->backgroundImagePath;
        }

        $variant->edit($backgroundImagePath);
    }
}
