<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\OAuth2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\ClientRegistrationFailed;
use WBoost\Web\Services\OAuth2\RedirectUriPolicy;

/**
 * The rule set that decides where an authorization code may be delivered for a
 * client an ANONYMOUS caller registered ({@see RedirectUriPolicy}).
 *
 * Tested directly rather than only through the endpoint: this is the security
 * contract, the interesting cases are the REJECTIONS, and a table of them is
 * both cheaper to read and impossible to accidentally satisfy with a 400 that
 * came from somewhere else in the request pipeline.
 */
final class RedirectUriPolicyTest extends TestCase
{
    #[DataProvider('acceptedUris')]
    public function testAnAcceptableRedirectUriPasses(string $redirectUri): void
    {
        // The assertion IS that nothing was thrown; PHPUnit needs telling, or
        // it reports the test as risky for making none.
        $this->expectNotToPerformAssertions();

        RedirectUriPolicy::assertRegistrable($redirectUri);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedUris(): iterable
    {
        yield 'a connector callback' => ['https://claude.ai/api/mcp/auth_callback'];
        yield 'https with a port' => ['https://example.com:8443/callback'];
        yield 'https with a query' => ['https://example.com/cb?tenant=42'];
        yield 'https at the root' => ['https://example.com'];

        // RFC 8252 §7.3: a native client listens on an ephemeral port on the
        // user's own machine, where there is no https to be had. This is the
        // exception that makes Claude Code's one-command install work.
        yield 'loopback IPv4' => ['http://127.0.0.1:41999/callback'];
        yield 'loopback IPv6' => ['http://[::1]:41999/callback'];
        yield 'loopback by name' => ['http://localhost:8080/oauth/callback'];

        // Case folding is applied to the SCHEME and the HOST, which RFC 3986
        // §6.2.2.1 says is the safe part to normalise. The PATH is left alone
        // and must survive registration byte for byte, or the exact match
        // league performs at the authorization endpoint fails for a URI we
        // ourselves accepted.
        yield 'loopback, uppercase host' => ['http://LOCALHOST:8080/cb'];
        yield 'uppercase scheme and host, mixed-case path' => ['HTTPS://Example.COM/CallBack'];
    }

    #[DataProvider('rejectedUris')]
    public function testAnUnacceptableRedirectUriIsRefused(string $redirectUri): void
    {
        $this->expectException(ClientRegistrationFailed::class);

        try {
            RedirectUriPolicy::assertRegistrable($redirectUri);
        } catch (ClientRegistrationFailed $failure) {
            // The code, not just the refusal: RFC 7591 §3.2.2 is what tells a
            // client to change its redirect URI rather than its metadata.
            self::assertSame(ClientRegistrationFailed::INVALID_REDIRECT_URI, $failure->error);

            throw $failure;
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedUris(): iterable
    {
        yield 'empty' => [''];

        // Plain http anywhere but the loopback interface: the code would travel
        // in clear text, and anyone on the path could read it.
        yield 'http on a public host' => ['http://example.com/cb'];
        yield 'http on a near-loopback address' => ['http://127.0.0.2/cb'];
        yield 'http on the wildcard address' => ['http://0.0.0.0:8080/cb'];
        yield 'http on a decimal-encoded loopback literal' => ['http://2130706433/cb'];
        yield 'http on a host that merely contains localhost' => ['http://localhost.evil.example/cb'];

        // Exact string matching is what league does at the authorization
        // endpoint, so a registered wildcard would match nothing — but the
        // caller would believe it had a pattern.
        yield 'a wildcard host' => ['https://*.example.com/cb'];
        yield 'a wildcard path' => ['https://example.com/*'];

        // ⚠️ THE ONE THAT IS NOT COSMETIC. The bundle stores all of a client's
        // redirect URIs SPACE-DELIMITED in a single TEXT column and splits on
        // the same character when reading, so a value containing a space is
        // read back as TWO registered URIs — the second one having passed
        // through no validation whatsoever.
        yield 'a second URI hidden behind a space' => ['https://ok.example/cb https://evil.example/cb'];
        yield 'a tab' => ["https://ok.example/cb\thttps://evil.example/cb"];
        yield 'a newline' => ["https://ok.example/cb\nhttps://evil.example/cb"];
        yield 'a NUL byte' => ["https://ok.example/cb\0"];

        // RFC 6749 §3.1.2 forbids a fragment on a redirection endpoint.
        yield 'a fragment' => ['https://example.com/cb#access_token'];

        // Renders as the userinfo in some UIs and as the host in others —
        // exactly the ambiguity a consent screen must not have to resolve.
        yield 'userinfo disguising the host' => ['https://claude.ai@evil.example/cb'];
        yield 'userinfo with a password' => ['https://user:pass@evil.example/cb'];

        // Private-use schemes (RFC 8252 §7.1) are first-come-first-served on
        // most desktops, so registering one reserves a hijack of whatever
        // unrelated application already claims it.
        yield 'a private-use scheme' => ['com.evil.app:/callback'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'data' => ['data:text/html,<script>x</script>'];
        yield 'file' => ['file:///etc/passwd'];

        yield 'a relative path' => ['/callback'];
        yield 'a bare host' => ['example.com/cb'];
        yield 'a scheme with no host' => ['https:///cb'];

        yield 'longer than the column can sanely hold' => ['https://example.com/' . str_repeat('a', 512)];
    }

}
