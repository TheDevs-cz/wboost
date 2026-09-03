<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\OAuth2;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Tests\TestingOAuthClient;

/**
 * The consent screen (S8-T5) — the thing that makes `/api/authorize` safe to
 * expose to self-registered clients.
 *
 * What is being guarded is not "a page renders". It is that **no token is ever
 * issued for a scope the user has not been shown**, which is the only property
 * that turns dynamic client registration from a one-click account takeover into
 * an ordinary OAuth connector flow. The escalation test below is the sharp end
 * of that: everything else can pass while an approval of `templates:read`
 * silently satisfies a later request for `templates:design`.
 */
final class ConsentTest extends WebTestCase
{
    private const string CONSENT_PATH = '/oauth/consent';

    /**
     * First contact with an unknown application: the browser is parked on the
     * consent screen instead of being handed a code.
     *
     * The three assertions are the three questions the screen has to answer —
     * WHO is asking, WHICH of my accounts is being connected (users here have
     * several, and connecting the wrong one is a real mistake), and WHAT will
     * they be able to do.
     */
    public function testFirstAuthorizationIsParkedOnTheConsentScreen(): void
    {
        $client = self::createClient();
        self::registerPublicClient($client);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery([McpScope::TemplatesRead->value])));

        self::assertResponseRedirects();
        self::assertStringContainsString(
            self::CONSENT_PATH,
            (string) $client->getResponse()->headers->get('Location'),
            'A first-time authorization handed out a code without asking.',
        );

        $crawler = $client->followRedirect();

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $page = $crawler->html();

        self::assertStringContainsString('mcp-test-public', $page, 'The screen did not name the application.');
        self::assertStringContainsString(TestDataFixture::USER_1_EMAIL, $page, 'The screen did not name the account being connected.');
        self::assertStringContainsString('Prohlížet vaše projekty a šablony', $page, 'The requested scope had no Czech description.');

        // No code was issued while the question was still open.
        self::assertStringNotContainsString('code=', (string) $client->getResponse()->headers->get('Location'));
    }

    /**
     * IMPLICATION IS VISIBLE. `templates:design` grants `templates:read`
     * ({@see McpScope::grants()}), and that expansion is applied at check time —
     * so a screen listing only the literal request would under-state the grant.
     *
     * Asserted from both ends: the implied line is present, AND it is labelled
     * as implied rather than presented as something the app asked for.
     */
    public function testImpliedScopesAreShownAndMarkedAsImplied(): void
    {
        $client = self::createClient();
        self::registerPublicClient($client);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $page = self::consentPage($client, [McpScope::TemplatesDesign->value]);

        self::assertStringContainsString('Vytvářet a přepisovat šablony', $page);
        self::assertStringContainsString('Prohlížet vaše projekty a šablony', $page, 'The implied read scope was not shown.');
        self::assertStringContainsString('Je součástí oprávnění', $page, 'The implied scope was not marked as implied.');
    }

    /**
     * Approving completes the very authorization that was interrupted — same
     * PKCE challenge, same `state`, code delivered to the client's own redirect
     * URI. A consent screen that lost any of those would leave the connector
     * unable to finish.
     */
    public function testApprovingCompletesTheAuthorization(): void
    {
        $client = self::createClient();
        self::registerPublicClient($client);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery([McpScope::TemplatesRead->value])));
        TestingOAuthClient::approveConsent($client);

        $params = self::redirectParams($client);

        self::assertArrayNotHasKey('error', $params);
        self::assertIsString($params['code'] ?? null);
        self::assertSame('opaque-state', $params['state'] ?? null);
    }

    /**
     * ⚠️ Refusal must be a REAL OAuth outcome, not a wboost error page.
     *
     * The client is waiting at its redirect URI, so the answer it is entitled to
     * is RFC 6749 §4.1.2.1 `access_denied` with its `state` echoed — which is
     * what league builds when the authorization resolves DENIED. Answering with
     * an HTML page instead would strand the connector on a screen its user has
     * already left.
     */
    public function testDenyingSendsAccessDeniedToTheClient(): void
    {
        $client = self::createClient();
        self::registerPublicClient($client);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery([McpScope::TemplatesRead->value])));
        TestingOAuthClient::denyConsent($client);

        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringStartsWith(TestingOAuthClient::REDIRECT_URI, $location, 'Denial did not go back to the client.');

        $params = self::redirectParams($client);

        self::assertSame('access_denied', $params['error'] ?? null);
        self::assertSame('opaque-state', $params['state'] ?? null);
        self::assertArrayNotHasKey('code', $params, 'A denied authorization still issued a code.');
    }

    /**
     * A remembered approval means no second prompt.
     *
     * This is not a convenience: `access_token_ttl` is one hour and refresh
     * tokens rotate, so without it a connector would put a consent screen in
     * front of the user every hour — and a screen people see hourly is a screen
     * people click through blind, which is the failure mode consent exists to
     * avoid.
     */
    public function testARememberedApprovalSkipsThePrompt(): void
    {
        $client = self::createClient();
        self::registerPublicClient($client);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery([McpScope::TemplatesRead->value])));
        TestingOAuthClient::approveConsent($client);
        self::assertIsString(self::redirectParams($client)['code'] ?? null);

        $client->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery([McpScope::TemplatesRead->value])));

        self::assertStringNotContainsString(
            self::CONSENT_PATH,
            (string) $client->getResponse()->headers->get('Location'),
            'The user was asked again about an application they had already approved.',
        );
        self::assertIsString(self::redirectParams($client)['code'] ?? null);
    }

    /**
     * ⚠️ THE security-relevant case: a stored approval must never cover a scope
     * the user has not seen.
     *
     * Approving `templates:read` says nothing about `templates:design` — which
     * can rewrite every template in every project the user can reach. If this
     * ever passes silently, "remembered approval" has become "permanent blanket
     * trust for this client", and an application could widen its own grant
     * after the fact simply by asking for more on its next reconnect.
     */
    public function testAskingForMoreThanWasApprovedPromptsAgain(): void
    {
        $client = self::createClient();
        self::registerPublicClient($client);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery([McpScope::TemplatesRead->value])));
        TestingOAuthClient::approveConsent($client);
        self::assertIsString(self::redirectParams($client)['code'] ?? null);

        $client->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery([McpScope::TemplatesDesign->value])));

        self::assertStringContainsString(
            self::CONSENT_PATH,
            (string) $client->getResponse()->headers->get('Location'),
            'A wider scope was granted on the strength of a narrower approval.',
        );
    }

    /**
     * The mirror image, and the reason the EFFECTIVE set is what gets stored:
     * approving `templates:design` has already shown the user the `templates:read`
     * line, so a later request for read alone is covered. Storing the literal
     * request instead would re-prompt here for something the user demonstrably
     * saw and agreed to.
     */
    public function testAnAlreadyImpliedScopeDoesNotPromptAgain(): void
    {
        $client = self::createClient();
        self::registerPublicClient($client);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery([McpScope::TemplatesDesign->value])));
        TestingOAuthClient::approveConsent($client);
        self::assertIsString(self::redirectParams($client)['code'] ?? null);

        $client->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery([McpScope::TemplatesRead->value])));

        self::assertStringNotContainsString(
            self::CONSENT_PATH,
            (string) $client->getResponse()->headers->get('Location'),
        );
    }

    /**
     * An approval belongs to ONE user. USER_2 approving nothing must not
     * inherit USER_1's decision about the same client — the stored row is
     * keyed on the pair, and a lookup that dropped the user would hand every
     * account's authorization straight through.
     */
    public function testAnotherUsersApprovalDoesNotCount(): void
    {
        $client = self::createClient();
        self::registerPublicClient($client);

        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $client->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery([McpScope::TemplatesRead->value])));
        TestingOAuthClient::approveConsent($client);
        self::assertIsString(self::redirectParams($client)['code'] ?? null);

        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);
        $client->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery([McpScope::TemplatesRead->value])));

        self::assertStringContainsString(
            self::CONSENT_PATH,
            (string) $client->getResponse()->headers->get('Location'),
            'One user\'s approval answered another user\'s authorization.',
        );
    }

    /**
     * CSRF is not decoration on this form: a cross-site POST that could approve
     * an application would hand the whole screen's purpose back to the attacker
     * — they would no longer need the user to click anything.
     */
    public function testAForgedConsentPostIsRejected(): void
    {
        $client = self::createClient();
        self::registerPublicClient($client);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery([McpScope::TemplatesRead->value])));
        $client->followRedirect();

        $client->request('POST', self::CONSENT_PATH, ['approve' => '1', '_token' => 'forged']);

        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());

        // And nothing was remembered, so the real flow still has to ask.
        $client->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery([McpScope::TemplatesRead->value])));
        self::assertStringContainsString(self::CONSENT_PATH, (string) $client->getResponse()->headers->get('Location'));
    }

    /**
     * The screen with nothing parked behind it — a bookmark, a reload after the
     * decision, an expired session. There is no authorization to answer and
     * nowhere to send the user, so it says so instead of rendering a form whose
     * buttons cannot do anything.
     */
    public function testTheConsentScreenWithoutAPendingRequestIsRefused(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', self::CONSENT_PATH);

        self::assertResponseRedirects('/dashboard');
    }

    /**
     * The consent screen is a normal authenticated page: an anonymous visitor
     * gets the login form, never a decision they are not signed in to make.
     */
    public function testTheConsentScreenRequiresALogin(): void
    {
        $client = self::createClient();

        $client->request('GET', self::CONSENT_PATH);

        self::assertResponseRedirects('http://localhost/login');
    }

    /**
     * `client_credentials` never reaches the consent machinery: it has no
     * resource owner and no browser, and league dispatches the resolve event
     * only from the authorization endpoint. The in-production REST API must
     * therefore behave exactly as it did before this task.
     */
    public function testClientCredentialsIsUnaffectedByConsent(): void
    {
        $client = self::createClient();

        $token = TestingOAuthClient::clientCredentialsToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        self::assertNotSame('', $token);

        $client->request('GET', '/api/projects', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
    }

    /**
     * Renders the consent screen for the given scopes and returns its HTML.
     *
     * @param list<string> $scopes
     */
    private static function consentPage(KernelBrowser $browser, array $scopes): string
    {
        $browser->request('GET', '/api/authorize?' . http_build_query(self::authorizeQuery($scopes)));

        return $browser->followRedirect()->html();
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function redirectParams(KernelBrowser $browser): array
    {
        parse_str(
            (string) parse_url((string) $browser->getResponse()->headers->get('Location'), PHP_URL_QUERY),
            $params,
        );

        return $params;
    }

    /**
     * @param list<string> $scopes
     *
     * @return array<string, string>
     */
    private static function authorizeQuery(array $scopes): array
    {
        return TestingOAuthClient::authorizeQuery($scopes);
    }

    private static function registerPublicClient(KernelBrowser $browser): void
    {
        TestingOAuthClient::registerPublicClient($browser->getContainer()->get(ClientManagerInterface::class));
    }
}
