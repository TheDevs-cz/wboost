<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialPost;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\SocialPost;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Entity\User;
use WBoost\Web\Repository\SocialPostRepository;
use WBoost\Web\Message\SocialPost\RecordSocialPost;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class RecordSocialPostHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SocialPostRepository $posts,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordSocialPost $message): void
    {
        $variant = $this->entityManager->find(TemplateVariant::class, $message->variantId);

        if (!$variant instanceof TemplateVariant) {
            return;
        }

        $user = $message->publishedByUserId !== null
            ? $this->entityManager->find(User::class, $message->publishedByUserId)
            : null;

        $this->posts->save(new SocialPost(
            $this->provideIdentity->next(),
            $variant->template->project,
            $variant,
            $variant->template->id,
            $variant->template->name,
            $message->platform,
            $message->kind,
            $message->accountId,
            $message->accountName,
            $message->externalId,
            $message->permalink,
            $message->caption,
            $user,
            $this->clock->now(),
            $message->fillValuesHash,
            $message->response,
        ));
    }
}
