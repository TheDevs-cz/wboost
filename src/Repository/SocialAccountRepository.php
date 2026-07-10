<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\SocialAccount;
use WBoost\Web\Entity\User;
use WBoost\Web\Value\SocialProvider;

readonly final class SocialAccountRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(SocialAccount $account): void
    {
        $this->entityManager->persist($account);
    }

    public function remove(SocialAccount $account): void
    {
        $this->entityManager->remove($account);
    }

    public function getById(UuidInterface $id): null|SocialAccount
    {
        return $this->entityManager->find(SocialAccount::class, $id);
    }

    public function findByProviderUserId(SocialProvider $provider, string $providerUserId): null|SocialAccount
    {
        $account = $this->entityManager->createQueryBuilder()
            ->from(SocialAccount::class, 'a')
            ->select('a')
            ->where('a.provider = :provider')
            ->andWhere('a.providerUserId = :providerUserId')
            ->setParameter('provider', $provider)
            ->setParameter('providerUserId', $providerUserId)
            ->getQuery()
            ->getOneOrNullResult();

        assert($account instanceof SocialAccount || $account === null);

        return $account;
    }

    public function findForUser(User $user, SocialProvider $provider): null|SocialAccount
    {
        $account = $this->entityManager->createQueryBuilder()
            ->from(SocialAccount::class, 'a')
            ->select('a')
            ->where('a.user = :user')
            ->andWhere('a.provider = :provider')
            ->setParameter('user', $user)
            ->setParameter('provider', $provider)
            ->getQuery()
            ->getOneOrNullResult();

        assert($account instanceof SocialAccount || $account === null);

        return $account;
    }
}
