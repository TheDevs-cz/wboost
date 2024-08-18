<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\SocialNetworkTemplate;
use WBoost\Web\Exceptions\SocialNetworkTemplateNotFound;
use Doctrine\ORM\EntityManagerInterface;

readonly final class SocialNetworkTemplateRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws SocialNetworkTemplateNotFound
     */
    public function get(UuidInterface $templateId): SocialNetworkTemplate
    {
        $template = $this->entityManager->find(SocialNetworkTemplate::class, $templateId);

        if ($template instanceof SocialNetworkTemplate) {
            return $template;
        }

        throw new SocialNetworkTemplateNotFound();
    }

    public function add(SocialNetworkTemplate $template): void
    {
        $this->entityManager->persist($template);
    }

    public function remove(SocialNetworkTemplate $template): void
    {
        $this->entityManager->remove($template);
    }
}
