<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialAccount;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\SocialAccount;
use WBoost\Web\Exceptions\SocialAccountAlreadyLinked;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Message\SocialAccount\ConnectFacebookAccount;
use WBoost\Web\Repository\SocialAccountRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\TokenCrypto;
use WBoost\Web\Value\SocialProvider;

#[AsMessageHandler]
readonly final class ConnectFacebookAccountHandler
{
    public function __construct(
        private SocialAccountRepository $socialAccountRepository,
        private UserRepository $userRepository,
        private TokenCrypto $tokenCrypto,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws SocialAccountAlreadyLinked
     * @throws UserNotFound
     */
    public function __invoke(ConnectFacebookAccount $message): void
    {
        $user = $this->userRepository->getById(Uuid::fromString($message->userId));

        $encryptedToken = $this->tokenCrypto->encrypt($message->accessToken);
        $expiresAt = $message->tokenExpiresAtTimestamp !== null
            ? DateTimeImmutable::createFromTimestamp($message->tokenExpiresAtTimestamp)
            : null;

        $existingByProvider = $this->socialAccountRepository->findByProviderUserId(
            SocialProvider::Facebook,
            $message->providerUserId,
        );

        if ($existingByProvider !== null) {
            if (!$existingByProvider->user->id->equals($user->id)) {
                throw new SocialAccountAlreadyLinked();
            }

            $existingByProvider->updateToken(
                $encryptedToken,
                $expiresAt,
                $message->scopes,
                $message->displayName,
                $this->clock->now(),
            );

            return;
        }

        // The user may be switching to a different Facebook account: drop the
        // old link first. The interim flush is needed because the UnitOfWork
        // executes INSERTs before DELETEs, which would trip the
        // (user_id, provider) unique constraint; the surrounding
        // doctrine_transaction middleware still makes both atomic.
        $previousLink = $this->socialAccountRepository->findForUser($user, SocialProvider::Facebook);

        if ($previousLink !== null) {
            $this->socialAccountRepository->remove($previousLink);
            $this->entityManager->flush();
        }

        $this->socialAccountRepository->save(new SocialAccount(
            $this->provideIdentity->next(),
            $user,
            SocialProvider::Facebook,
            $message->providerUserId,
            $encryptedToken,
            $expiresAt,
            $message->scopes,
            $message->displayName,
            $this->clock->now(),
        ));
    }
}
