<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Flyer;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\FlyerTemplateVariant;
use WBoost\Web\Exceptions\FlyerTemplateNotFound;
use WBoost\Web\Message\Flyer\AddFlyerTemplateVariant;
use WBoost\Web\Repository\FlyerTemplateRepository;
use WBoost\Web\Repository\FlyerTemplateVariantRepository;

#[AsMessageHandler]
readonly final class AddFlyerTemplateVariantHandler
{
    public function __construct(
        private FlyerTemplateRepository $templateRepository,
        private FlyerTemplateVariantRepository $variantRepository,
        private ClockInterface $clock,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws FlyerTemplateNotFound
     */
    public function __invoke(AddFlyerTemplateVariant $message): void
    {
        $template = $this->templateRepository->get($message->templateId);
        $variantId = $message->variantId;
        $backgroundImage = $message->backgroundImage;

        // Maybe in future it will be possible to not have background when creating
        assert($backgroundImage !== null);

        $timestamp = $this->clock->now()->getTimestamp();

        $extension = $backgroundImage->guessExtension();
        $backgroundImagePath = "flyers/$variantId/background-$timestamp.$extension";
        $this->filesystem->write($backgroundImagePath, $backgroundImage->getContent());

        $variant = new FlyerTemplateVariant(
            $variantId,
            $template,
            $message->dimension,
            $backgroundImagePath,
            $this->clock->now(),
        );

        $this->variantRepository->add($variant);
    }
}
