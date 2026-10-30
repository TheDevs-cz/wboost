<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Message\Template\SortTemplates;

#[AsMessageHandler]
readonly final class SortTemplatesHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(SortTemplates $message): void
    {
        // Templates and multi-page documents share the listing's sort space,
        // so an id may name either.
        foreach ($message->templates as $position => $id) {
            if (!Uuid::isValid($id)) {
                continue;
            }

            $uuid = Uuid::fromString($id);
            $item = $this->entityManager->find(Template::class, $uuid) ?? $this->entityManager->find(TemplateDocument::class, $uuid);

            $item?->sort($position);
        }
    }
}
