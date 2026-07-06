<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\TemplateGroup;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\SocialNetworkTemplate;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Exceptions\TemplateGroupNotFound;
use WBoost\Web\Message\TemplateGroup\AddTemplateGroupSocialDimension;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Repository\SocialNetworkTemplateRepository;
use WBoost\Web\Repository\SocialNetworkTemplateVariantRepository;
use WBoost\Web\Repository\TemplateGroupRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class AddTemplateGroupSocialDimensionHandler
{
    public function __construct(
        private TemplateGroupRepository $templateGroupRepository,
        private GetTemplateGroupMembers $members,
        private SocialNetworkTemplateRepository $templateRepository,
        private SocialNetworkTemplateVariantRepository $variantRepository,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @throws TemplateGroupNotFound
     */
    public function __invoke(AddTemplateGroupSocialDimension $message): void
    {
        $group = $this->templateGroupRepository->get($message->groupId);
        $template = $this->members->socialTemplate($group->id);

        // A group created without this module gets its module template lazily.
        if ($template === null) {
            $template = new SocialNetworkTemplate(
                $this->provideIdentity->next(),
                $group->project,
                null,
                $this->clock->now(),
                $group->name,
                null,
                $this->templateRepository->count($group->project->id),
            );

            $template->assignToGroup($group);
            $this->templateRepository->add($template);
        }

        $variantId = $message->variantId;
        $timestamp = $this->clock->now()->getTimestamp();
        $extension = $message->backgroundImage->guessExtension();

        $backgroundImagePath = "social-networks/$variantId/background-$timestamp.$extension";
        $this->filesystem->write($backgroundImagePath, $message->backgroundImage->getContent());

        $variant = new SocialNetworkTemplateVariant(
            $variantId,
            $template,
            $message->dimension,
            $backgroundImagePath,
            $this->clock->now(),
        );

        $variant->assignToGroup($group);
        $this->variantRepository->add($variant);
    }
}
