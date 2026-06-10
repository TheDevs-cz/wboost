<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\CustomTemplate;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Exceptions\CustomTemplateNotFound;
use WBoost\Web\Message\CustomTemplate\AddCustomTemplateVariant;
use WBoost\Web\Repository\CustomTemplateRepository;
use WBoost\Web\Repository\CustomTemplateVariantRepository;

#[AsMessageHandler]
readonly final class AddCustomTemplateVariantHandler
{
    public function __construct(
        private CustomTemplateRepository $templateRepository,
        private CustomTemplateVariantRepository $variantRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws CustomTemplateNotFound
     */
    public function __invoke(AddCustomTemplateVariant $message): void
    {
        $template = $this->templateRepository->get($message->templateId);
        $variantId = $message->variantId;
        $backgroundImage = $message->backgroundImage;

        // Maybe in future it will be possible to not have background when creating
        assert($backgroundImage !== null);

        $timestamp = $this->clock->now()->getTimestamp();

        $extension = $backgroundImage->guessExtension();
        $backgroundImagePath = "custom-templates/$variantId/background-$timestamp.$extension";
        $this->filesystem->write($backgroundImagePath, $backgroundImage->getContent());

        $variant = new CustomTemplateVariant(
            $variantId,
            $template,
            $message->dimension,
            $backgroundImagePath,
            $this->clock->now(),
        );

        $this->variantRepository->add($variant);
    }
}
