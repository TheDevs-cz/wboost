<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\CustomTemplate;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\CustomTemplateVariantNotFound;
use WBoost\Web\Message\CustomTemplate\EditCustomTemplateVariant;
use WBoost\Web\Repository\CustomTemplateVariantRepository;

#[AsMessageHandler]
readonly final class EditCustomTemplateVariantHandler
{
    public function __construct(
        private CustomTemplateVariantRepository $variantRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws CustomTemplateVariantNotFound
     */
    public function __invoke(EditCustomTemplateVariant $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);

        $backgroundImagePath = $variant->backgroundImage;
        $backgroundImage = $message->backgroundImage;

        if ($backgroundImage !== null) {
            // Raw-upload path: store the file alongside the variant.
            $timestamp = $this->clock->now()->getTimestamp();

            $extension = $backgroundImage->guessExtension();
            $backgroundImagePath = "custom-templates/$variant->id/background-$timestamp.$extension";
            $this->filesystem->write($backgroundImagePath, $backgroundImage->getContent());
        } elseif ($message->backgroundImagePath !== null) {
            // Gallery path: the asset already lives in S3/Minio under
            // file-upload/{projectId}/{fileId}.{ext} as a FileUpload row; just
            // point the variant at it.
            $backgroundImagePath = $message->backgroundImagePath;
        }

        $variant->edit($backgroundImagePath);
    }
}
