<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
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
        // The token is a raw URL segment, so it is not necessarily a UUID at
        // all — a mail client that truncates the link, or a scanner that
        // rewrites it, hands us garbage. Doctrine's uuid type then throws
        // ValueNotConvertible while BINDING the parameter (an uncaught 500)
        // instead of simply matching no row, so unparseable ids are rejected
        // before they reach the query: an unreadable token is an invalid one.
        if (!Uuid::isValid($tokenId)) {
            throw new InvalidPasswordResetToken();
        }

        $token = $this->entityManager->find(PasswordResetToken::class, $tokenId);

        if ($token instanceof PasswordResetToken) {
            return $token;
        }

        throw new InvalidPasswordResetToken();
    }

    /**
     * @throws InvalidPasswordResetToken
     */
    public function getValid(string $tokenId, DateTimeImmutable $now): PasswordResetToken
    {
        $token = $this->get($tokenId);

        if ($token->usedAt !== null || $token->validUntil < $now) {
            throw new InvalidPasswordResetToken();
        }

        return $token;
    }
}
