<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\NoResultException;
use WBoost\Web\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
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
    public function get(string $email): User
    {
        try {
            $row = $this->entityManager->createQueryBuilder()
                ->from(User::class, 'u')
                ->select('u')
                ->where('u.email = :email')
                ->setParameter('email', $email)
                ->getQuery()
                ->getSingleResult();

            assert($row instanceof User);
            return $row;
        } catch (NoResultException) {
            throw new UserNotFound();
        }
    }
}
