<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Exceptions\UserNotFound;

readonly final class UserRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(User $user): void
    {
        $this->entityManager->persist($user);
    }

    /**
     * @throws UserNotFound
     */
    public function get(UuidInterface $userId): User
    {
        $user = $this->entityManager->find(User::class, $userId);

        if ($user === null) {
            throw new ProjectNotFound();
        }

        return $user;
    }
}
