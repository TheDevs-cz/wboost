<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use WBoost\Web\Entity\PasswordResetToken;
use Doctrine\ORM\EntityManagerInterface;
use WBoost\Web\Exceptions\InvalidPasswordResetToken;

readonly final class PasswordResetTokenRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(PasswordResetToken $token): void
    {
        $this->entityManager->persist($token);
    }

    /**
     * @throws InvalidPasswordResetToken
     */
    public function get(string $tokenId): PasswordResetToken
    {
        $token = $this->entityManager->find(PasswordResetToken::class, $tokenId);

        if ($token instanceof PasswordResetToken) {
            return $token;
        }

        throw new InvalidPasswordResetToken();
    }
}
