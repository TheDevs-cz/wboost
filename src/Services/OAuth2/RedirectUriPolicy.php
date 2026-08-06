<?php

declare(strict_types=1);

namespace WBoost\Web\Services\OAuth2;

use WBoost\Web\Exceptions\ClientRegistrationFailed;

/**
 * What a redirect URI has to look like before an ANONYMOUS caller is allowed to
 * register it ({@see DynamicClientRegistrar}).
 *
 * A redirect URI is where an authorization code is delivered, so this list is
 * the difference between "a code reaches the client that asked for it" and "a
 * code reaches whoever crafted the registration". None of these rules make
 * unattended registration SAFE on their own — only a consent screen does, see
 * DynamicClientRegistrar's docblock — but each one closes a way to abuse a
 * registration that a user did approve.
 *
 * Pure static, like {@see \WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories}'s
 * `effectiveIds()`: no state, no dependencies, and unit-testable without a
 * container — which matters, because this is the class whose rejection paths
 * are the security contract.
 */
final readonly class RedirectUriPolicy
{
    /**
     * Long enough for any real callback, short enough that `redirect_uris`
     * cannot be used as a text field. The column holds all of a client's URIs
     * in one TEXT value.
     */
    private const int MAX_LENGTH = 512;

    /**
     * The loopback exception (RFC 8252 §7.3 / OAuth 2.1 §4.1.3): a NATIVE
     * client — Claude Code, an MCP CLI — receives its callback on a port it
     * opened on the user's own machine, where there is no https to be had. The
     * hosts are literal on purpose: `127.0.0.1` and `[::1]` are the addresses
     * RFC 8252 names, `localhost` is added because that is what the clients in
     * question actually register. Nothing else — no `127.0.0.2`, no
     * `0.0.0.0`, no decimal-encoded literal — because every one of those is a
     * way to write an address that LOOKS local to a reviewer.
     *
     * @var list<string>
     */
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * The `@phpstan-assert` is not decoration: `RedirectUri`'s constructor
     * demands a `non-empty-string`, and this method is the only thing that
     * establishes it. Stating it here means the caller gets the narrowing for
     * free instead of re-checking (or silencing) it.
     *
     * @phpstan-assert non-empty-string $redirectUri
     *
     * @throws ClientRegistrationFailed
     */
    public static function assertRegistrable(string $redirectUri): void
    {
        if ($redirectUri === '') {
            throw ClientRegistrationFailed::invalidRedirectUri('A redirect URI cannot be empty.');
        }

        if (strlen($redirectUri) > self::MAX_LENGTH) {
            throw ClientRegistrationFailed::invalidRedirectUri(sprintf(
                'A redirect URI can be at most %d characters long.',
                self::MAX_LENGTH,
            ));
        }

        // NOT cosmetic. The bundle stores every redirect URI of a client in ONE
        // TEXT column, SPACE-DELIMITED
        // (League\Bundle\OAuth2ServerBundle\DBAL\Type\ImplodedArray), and
        // splits on the same character when reading. A single registered value
        // of "https://ok.example/cb https://evil.example/cb" would therefore
        // come back as TWO registered redirect URIs, one of which nothing ever
        // validated.
        // Matched BYTE-WISE (no `u` modifier) on purpose: a `/u` pattern
        // returns false — not "no match" — on a string that is not valid UTF-8,
        // which would turn the one check that must never be skipped into a
        // silent pass.
        if (preg_match('/[\s\x00-\x1F\x7F]/', $redirectUri) === 1) {
            throw ClientRegistrationFailed::invalidRedirectUri(
                'A redirect URI cannot contain whitespace or control characters.',
            );
        }

        // Exact string matching is what league does at the authorization
        // endpoint, so a wildcard would never match anything anyway — but a
        // caller that registers one believes it has a pattern. Say no out loud.
        if (str_contains($redirectUri, '*')) {
            throw ClientRegistrationFailed::invalidRedirectUri(
                'Wildcards are not supported: register each redirect URI in full.',
            );
        }

        $parts = parse_url($redirectUri);

        if ($parts === false) {
            throw ClientRegistrationFailed::invalidRedirectUri(sprintf('"%s" is not a valid URI.', $redirectUri));
        }

        // RFC 6749 §3.1.2: "The redirection endpoint URI MUST be an absolute
        // URI … MUST NOT include a fragment component."
        if (isset($parts['fragment'])) {
            throw ClientRegistrationFailed::invalidRedirectUri('A redirect URI cannot contain a fragment.');
        }

        // `https://user:pass@host/` renders as the userinfo in some UIs and as
        // the host in others — precisely the ambiguity a consent screen must
        // not have to resolve.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw ClientRegistrationFailed::invalidRedirectUri('A redirect URI cannot carry userinfo.');
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : null;
        $host = isset($parts['host']) ? strtolower($parts['host']) : null;

        if ($scheme === null || $host === null || $host === '') {
            throw ClientRegistrationFailed::invalidRedirectUri(
                'A redirect URI must be absolute and name a host, e.g. "https://example.com/callback".',
            );
        }

        if ($scheme === 'https') {
            return;
        }

        if ($scheme === 'http' && in_array($host, self::LOOPBACK_HOSTS, true)) {
            return;
        }

        if ($scheme === 'http') {
            throw ClientRegistrationFailed::invalidRedirectUri(sprintf(
                'Plain http is only accepted on the loopback interface (%s), so "%s" is refused. '
                . 'Use https, or a loopback address if this is a native client.',
                implode(', ', self::LOOPBACK_HOSTS),
                $redirectUri,
            ));
        }

        // Private-use schemes (RFC 8252 §7.1, e.g. `com.example.app:/cb`) are
        // the other native-app pattern, and they are refused deliberately:
        // scheme registration is first-come-first-served on most desktops, so
        // accepting an arbitrary one lets a registration reserve a hijack of
        // whatever an unrelated application already claims. The loopback
        // exception above covers the same clients without that.
        throw ClientRegistrationFailed::invalidRedirectUri(sprintf(
            'Unsupported redirect URI scheme "%s". Use https, or http on the loopback interface.',
            $scheme,
        ));
    }
}
