<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use WBoost\Web\Services\Security\ScannerServiceAuthenticator;

/**
 * Who is the scanner: the TCP peer inside the scan network AND the token.
 * Unset configuration lets nobody through.
 */
final class ScannerServiceAuthenticatorTest extends TestCase
{
    private const string TOKEN = 'current-token-0123456789abcdef';
    private const string PREVIOUS = 'previous-token-0123456789abcdef';

    /**
     * @return iterable<string, array{string, string, string, null|string, array<string, string>, bool}>
     */
    public static function requests(): iterable
    {
        $both = self::TOKEN . ',' . self::PREVIOUS;

        yield 'inside, current token' => ['10.99.0.0/24', $both, '10.99.0.5', self::TOKEN, [], true];
        yield 'inside, previous token (rotation)' => ['10.99.0.0/24', $both, '10.99.0.5', self::PREVIOUS, [], true];
        yield 'second network of a list' => ['10.99.0.0/24, 172.31.250.0/24', $both, '172.31.250.7', self::TOKEN, [], true];
        yield 'outside, valid token' => ['10.99.0.0/24', $both, '10.99.1.5', self::TOKEN, [], false];
        yield 'public address, valid token' => ['10.99.0.0/24', $both, '203.0.113.9', self::TOKEN, [], false];
        yield 'inside, wrong token' => ['10.99.0.0/24', $both, '10.99.0.5', 'wrong-token-0123456789abcdef00', [], false];
        yield 'inside, no token' => ['10.99.0.0/24', $both, '10.99.0.5', null, [], false];
        yield 'forged X-Forwarded-For from outside' => ['10.99.0.0/24', $both, '172.18.0.3', self::TOKEN, ['HTTP_X_FORWARDED_FOR' => '10.99.0.5'], false];
        yield 'X-Forwarded-For from inside (came through a proxy)' => ['10.99.0.0/24', $both, '10.99.0.5', self::TOKEN, ['HTTP_X_FORWARDED_FOR' => '10.99.0.6'], false];
        yield 'Forwarded header' => ['10.99.0.0/24', $both, '10.99.0.5', self::TOKEN, ['HTTP_FORWARDED' => 'for=10.99.0.6'], false];
        yield 'subnet unset' => ['', $both, '10.99.0.5', self::TOKEN, [], false];
        yield 'subnet blank' => [' , ', $both, '127.0.0.1', self::TOKEN, [], false];
        yield 'token unset' => ['10.99.0.0/24', '', '10.99.0.5', '', [], false];
        yield 'token unset, empty bearer' => ['10.99.0.0/24', '', '10.99.0.5', null, ['HTTP_AUTHORIZATION' => 'Bearer '], false];
        yield 'both unset (production default)' => ['', '', '127.0.0.1', '', [], false];
        yield 'a token too short to be one is not configured' => ['10.99.0.0/24', 'short', '10.99.0.5', 'short', [], false];
        yield 'everything open is still only that network' => ['0.0.0.0/0', $both, '8.8.8.8', self::TOKEN, [], true];
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('requests')]
    public function testWhoPasses(string $cidr, string $tokens, string $peer, null|string $token, array $server, bool $passes): void
    {
        $authenticator = new ScannerServiceAuthenticator($cidr, $tokens);
        $request = Request::create('/internal/scanner/tick', 'POST', server: [
            'REMOTE_ADDR' => $peer,
            ...($token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]),
            ...$server,
        ]);

        self::assertTrue($authenticator->supports($request), 'every request of the firewall is judged');

        try {
            $passport = $authenticator->authenticate($request);
            self::assertTrue($passes, 'must be refused');
            self::assertSame([ScannerServiceAuthenticator::ROLE], $passport->getUser()->getRoles());
        } catch (AuthenticationException $refused) {
            self::assertFalse($passes, 'must pass');

            $response = $authenticator->onAuthenticationFailure($request, $refused);
            self::assertSame(404, $response->getStatusCode());
            self::assertSame('', $response->getContent());
        }
    }

    public function testTheEntryPointIsA404Too(): void
    {
        $response = (new ScannerServiceAuthenticator('', ''))->start(Request::create('/internal/scanner/claim'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }
}
