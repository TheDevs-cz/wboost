<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Http;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use WBoost\Web\Services\Http\SubmitTokenCookieListener;

/**
 * The site-wide form guard (assets/submit_guard.js) learns that a native
 * post was answered — crucially a DOWNLOAD, which leaves the page in place —
 * from the cookie `wbsubmit_<token>` echoed on whatever the server answers.
 *
 * @covers \WBoost\Web\Services\Http\SubmitTokenCookieListener
 */
final class SubmitTokenCookieListenerTest extends WebTestCase
{
    public function testAPostCarryingTheTokenGetsItEchoedAsAPageReadableCookie(): void
    {
        $client = self::createClient();

        // Any POST route answers — the login check redirects; the cookie
        // rides on that answer like on a download's.
        $client->request('POST', '/login', ['_username' => 'nobody@test.cz', '_password' => 'x', SubmitTokenCookieListener::FIELD => 'abc123xyz9']);

        $cookie = self::cookie($client, SubmitTokenCookieListener::COOKIE_PREFIX . 'abc123xyz9');
        self::assertNotNull($cookie, 'the token must come back as a cookie');
        self::assertSame('1', $cookie->getValue());
        self::assertFalse($cookie->isHttpOnly(), 'the page polls for it');
        self::assertSame('/', $cookie->getPath());
        self::assertGreaterThan(time(), $cookie->getExpiresTime());
    }

    public function testATokenOfAnotherShapeIsNotEchoed(): void
    {
        $client = self::createClient();

        $client->request('POST', '/login', [SubmitTokenCookieListener::FIELD => 'Session; Path=/']);

        self::assertSame([], self::submitCookies($client));
    }

    public function testAGetIsNeverAnswered(): void
    {
        $client = self::createClient();

        $client->request('GET', '/login', [SubmitTokenCookieListener::FIELD => 'abc123xyz9']);

        self::assertSame([], self::submitCookies($client));
    }

    private static function cookie(KernelBrowser $client, string $name): null|Cookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function submitCookies(KernelBrowser $client): array
    {
        return array_values(array_filter(
            array_map(static fn (Cookie $cookie): string => $cookie->getName(), $client->getResponse()->headers->getCookies()),
            static fn (string $name): bool => str_starts_with($name, SubmitTokenCookieListener::COOKIE_PREFIX),
        ));
    }
}
