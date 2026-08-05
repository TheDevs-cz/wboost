<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\McpAccessToken;
use WBoost\Web\Entity\User;
use WBoost\Web\Value\McpAccessTokenStatus;

/**
 * The lifecycle state of an MCP access token — derived from two nullable
 * columns and an instant, so it is a pure function and tested as one (no
 * kernel, no database).
 *
 * This used to be a private display helper inside `app:mcp:token:list`, which
 * made the rules reachable only by scraping a rendered table.
 */
final class McpAccessTokenStatusTest extends TestCase
{
    private const string NOW = '2026-08-05 12:00:00';

    public function testATokenWithoutAnExpiryIsActiveForever(): void
    {
        $token = self::token(expiresAt: null);

        self::assertSame(McpAccessTokenStatus::Active, $token->status(self::now()));
        self::assertTrue($token->isActive(self::now()));
        self::assertFalse($token->isRevoked());
        self::assertNull($token->statusChangedAt(self::now()), 'An active token has nothing to date.');
    }

    public function testATokenExpiringLaterIsStillActive(): void
    {
        $token = self::token(expiresAt: self::now()->modify('+1 second'));

        self::assertSame(McpAccessTokenStatus::Active, $token->status(self::now()));
        self::assertTrue($token->isActive(self::now()));
    }

    public function testATokenPastItsExpiryIsExpired(): void
    {
        $expiresAt = self::now()->modify('-1 day');
        $token = self::token(expiresAt: $expiresAt);

        self::assertSame(McpAccessTokenStatus::Expired, $token->status(self::now()));
        self::assertFalse($token->isActive(self::now()));
        self::assertFalse($token->isRevoked(), 'Ageing out is not a revocation.');
        self::assertSame($expiresAt, $token->statusChangedAt(self::now()));
    }

    /**
     * The boundary is EXCLUSIVE, mirroring `expiresAt > :now` in
     * `McpAccessTokenRepository::findActiveByHash()`. If these two drifted, the
     * listing would call a token active that cannot authenticate.
     */
    public function testExpiryAtTheExactInstantIsAlreadyExpired(): void
    {
        $token = self::token(expiresAt: self::now());

        self::assertSame(McpAccessTokenStatus::Expired, $token->status(self::now()));
        self::assertFalse($token->isActive(self::now()));
    }

    public function testARevokedTokenIsRevoked(): void
    {
        $revokedAt = self::now()->modify('-1 hour');
        $token = self::token(expiresAt: null);
        $token->revoke($revokedAt);

        self::assertSame(McpAccessTokenStatus::Revoked, $token->status(self::now()));
        self::assertTrue($token->isRevoked());
        self::assertFalse($token->isActive(self::now()));
        self::assertSame($revokedAt, $token->statusChangedAt(self::now()));
    }

    /**
     * Revocation WINS over expiry when both apply.
     *
     * Either state denies access, so nothing about authentication rests on the
     * precedence — it is entirely about what the operator is told. "Expired"
     * would read as if the token had merely aged out and hide the fact that
     * somebody killed it, which is the more important half of the audit trail.
     */
    public function testRevocationWinsOverExpiry(): void
    {
        $revokedAt = self::now()->modify('-1 hour');
        $token = self::token(expiresAt: self::now()->modify('-1 day'));
        $token->revoke($revokedAt);

        self::assertSame(McpAccessTokenStatus::Revoked, $token->status(self::now()));
        self::assertSame($revokedAt, $token->statusChangedAt(self::now()));
    }

    /**
     * `revoke()` is idempotent: an operator who is not sure whether the first
     * attempt landed must be able to run it again without rewriting history.
     */
    public function testRevokingTwiceKeepsTheFirstTimestamp(): void
    {
        $firstAttempt = self::now()->modify('-1 hour');

        $token = self::token(expiresAt: null);
        $token->revoke($firstAttempt);
        $token->revoke(self::now());

        self::assertSame($firstAttempt, $token->statusChangedAt(self::now()));
    }

    /**
     * `isActive()` must be exactly "status is Active" — two predicates that can
     * disagree is the bug this pairing exists to prevent.
     */
    #[DataProvider('statuses')]
    public function testIsActiveAgreesWithStatus(McpAccessToken $token, McpAccessTokenStatus $expected): void
    {
        self::assertSame($expected, $token->status(self::now()));
        self::assertSame($expected === McpAccessTokenStatus::Active, $token->isActive(self::now()));
    }

    /**
     * @return iterable<string, array{McpAccessToken, McpAccessTokenStatus}>
     */
    public static function statuses(): iterable
    {
        yield 'never expires' => [self::token(null), McpAccessTokenStatus::Active];
        yield 'expires later' => [self::token(self::now()->modify('+1 day')), McpAccessTokenStatus::Active];
        yield 'expired' => [self::token(self::now()->modify('-1 day')), McpAccessTokenStatus::Expired];

        $revoked = self::token(null);
        $revoked->revoke(self::now()->modify('-1 hour'));
        yield 'revoked' => [$revoked, McpAccessTokenStatus::Revoked];

        $both = self::token(self::now()->modify('-1 day'));
        $both->revoke(self::now()->modify('-1 hour'));
        yield 'revoked and expired' => [$both, McpAccessTokenStatus::Revoked];
    }

    private static function token(null|DateTimeImmutable $expiresAt): McpAccessToken
    {
        return new McpAccessToken(
            Uuid::uuid7(),
            new User(Uuid::uuid7(), 'agent@test.cz', self::now()->modify('-1 year')),
            'Test agent',
            ['templates:read'],
            str_repeat('a', 64),
            self::now()->modify('-1 month'),
            $expiresAt,
        );
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }
}
