<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpScopeChecker;

/**
 * Locks the scope implication matrix and the checker's fail-closed contract.
 *
 * The matrix below is the ONLY statement of what each scope carries; both the
 * per-pair grid and the coverage guard read from it, so a scope added to the
 * enum without a decision recorded here fails loudly rather than shipping with
 * undefined implications.
 */
final class McpScopeTest extends TestCase
{
    /**
     * The full expectation, keyed by scope value: exactly which scopes a token
     * holding that ONE scope effectively carries. Anything absent must be
     * denied.
     *
     * @return array<string, list<McpScope>>
     */
    private static function expectedGrants(): array
    {
        return [
            McpScope::TemplatesRead->value => [McpScope::TemplatesRead],
            McpScope::TemplatesExport->value => [McpScope::TemplatesExport, McpScope::TemplatesRead],
            McpScope::TemplatesDesign->value => [McpScope::TemplatesDesign, McpScope::TemplatesRead],
            McpScope::GalleryWrite->value => [McpScope::GalleryWrite],
        ];
    }

    /**
     * The guard that makes the matrix un-forgettable.
     */
    public function testEveryScopeHasItsImplicationsStated(): void
    {
        self::assertEqualsCanonicalizing(
            array_map(static fn (McpScope $scope): string => $scope->value, McpScope::cases()),
            array_keys(self::expectedGrants()),
            'A McpScope case was added or removed without updating the implication matrix in this test.',
        );
    }

    /**
     * The 4x4 grid: every scope asserted against every scope, both ways round.
     */
    #[DataProvider('scopePairs')]
    public function testImplicationMatrix(McpScope $held, McpScope $required, bool $expected): void
    {
        self::assertSame($expected, in_array($required, $held->grants(), true));
    }

    /**
     * @return iterable<string, array{McpScope, McpScope, bool}>
     */
    public static function scopePairs(): iterable
    {
        $matrix = self::expectedGrants();

        foreach (McpScope::cases() as $held) {
            foreach (McpScope::cases() as $required) {
                $grants = in_array($required, $matrix[$held->value] ?? [], true);

                $name = sprintf(
                    '%s %s %s',
                    $held->value,
                    $grants ? 'grants' : 'does not grant',
                    $required->value,
                );

                yield $name => [$held, $required, $grants];
            }
        }
    }

    #[DataProvider('scopes')]
    public function testGrantsReturnsExactlyTheExpectedSetAndAlwaysIncludesItself(McpScope $scope): void
    {
        $expected = self::expectedGrants()[$scope->value] ?? null;

        self::assertNotNull($expected, sprintf('No implications stated for %s.', $scope->value));
        self::assertEqualsCanonicalizing($expected, $scope->grants());
        self::assertSame($scope, $scope->grants()[0], 'A scope must grant itself, first.');
    }

    /**
     * @return iterable<string, array{McpScope}>
     */
    public static function scopes(): iterable
    {
        foreach (McpScope::cases() as $scope) {
            yield $scope->value => [$scope];
        }
    }

    public function testFromStringsDropsUnknownValuesInsteadOfThrowing(): void
    {
        // A scope string a later release removed (or a hand-edited row) must
        // never break an otherwise valid token.
        self::assertSame(
            [McpScope::TemplatesRead, McpScope::GalleryWrite],
            McpScope::fromStrings(['templates:read', 'templates:admin', 'gallery:write', '']),
        );
    }

    public function testFromStringsDeduplicatesAndPreservesOrder(): void
    {
        self::assertSame(
            [McpScope::GalleryWrite, McpScope::TemplatesRead],
            McpScope::fromStrings(['gallery:write', 'templates:read', 'gallery:write']),
        );
    }

    public function testFromStringsOfNothingGrantsNothing(): void
    {
        self::assertSame([], McpScope::fromStrings([]));
    }

    public function testCheckerExpandsImplicationsOfTheCurrentToken(): void
    {
        $checker = self::checker(self::tokenWithScopes(['templates:design']));

        self::assertTrue($checker->granted(McpScope::TemplatesDesign));
        self::assertTrue($checker->granted(McpScope::TemplatesRead), 'design must imply read');
        self::assertFalse($checker->granted(McpScope::TemplatesExport));
        self::assertFalse($checker->granted(McpScope::GalleryWrite));

        self::assertEqualsCanonicalizing(
            [McpScope::TemplatesDesign, McpScope::TemplatesRead],
            $checker->grantedScopes(),
        );
    }

    public function testCheckerDeduplicatesOverlappingScopes(): void
    {
        $checker = self::checker(self::tokenWithScopes(['templates:export', 'templates:design']));

        self::assertEqualsCanonicalizing(
            [McpScope::TemplatesExport, McpScope::TemplatesDesign, McpScope::TemplatesRead],
            $checker->grantedScopes(),
        );
    }

    public function testCheckerIgnoresUnknownAndNonStringScopeEntries(): void
    {
        $checker = self::checker(self::tokenWithScopes(['templates:read', 'templates:admin', 42, null]));

        self::assertSame([McpScope::TemplatesRead], $checker->grantedScopes());
        self::assertFalse($checker->granted(McpScope::GalleryWrite));
    }

    /**
     * Fail closed: an unauthenticated request grants nothing at all.
     */
    public function testCheckerGrantsNothingWithoutASecurityToken(): void
    {
        $checker = self::checker(null);

        self::assertSame([], $checker->grantedScopes());

        foreach (McpScope::cases() as $scope) {
            self::assertFalse($checker->granted($scope), sprintf('%s must not be granted.', $scope->value));
        }
    }

    /**
     * Fail closed: a token from another firewall (a web session, an OAuth API
     * client) carries no scopes attribute at all.
     */
    public function testCheckerGrantsNothingWhenTheAttributeIsMissing(): void
    {
        $checker = self::checker(self::token());

        self::assertSame([], $checker->grantedScopes());
        self::assertFalse($checker->granted(McpScope::TemplatesRead));
    }

    /**
     * Fail closed: a malformed attribute is "no scopes", never "all scopes".
     */
    public function testCheckerGrantsNothingWhenTheAttributeIsNotAList(): void
    {
        $token = self::token();
        $token->setAttribute(McpScopeChecker::TOKEN_ATTRIBUTE, 'templates:read');

        $checker = self::checker($token);

        self::assertSame([], $checker->grantedScopes());
        self::assertFalse($checker->granted(McpScope::TemplatesRead));
    }

    /**
     * @param array<int, mixed> $scopes
     */
    private static function tokenWithScopes(array $scopes): TokenInterface
    {
        $token = self::token();
        $token->setAttribute(McpScopeChecker::TOKEN_ATTRIBUTE, $scopes);

        return $token;
    }

    private static function token(): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser('mcp@example.com', null), 'mcp');
    }

    private static function checker(null|TokenInterface $token): McpScopeChecker
    {
        $tokenStorage = new TokenStorage();

        if ($token !== null) {
            $tokenStorage->setToken($token);
        }

        return new McpScopeChecker(new Security(new ServiceLocator([
            'security.token_storage' => static fn (): TokenStorageInterface => $tokenStorage,
        ])));
    }
}
