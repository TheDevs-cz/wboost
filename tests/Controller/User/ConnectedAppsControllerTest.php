<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\User;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Mcp\TestingMcpClient;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Tests\TestingOAuthClient;

/**
 * "Propojené aplikace" (S8-T5) — the page where a user sees what can reach
 * their projects, and cuts it off.
 *
 * The load-bearing test here is {@see testRevokingKillsALiveAccessToken()}. A
 * revoke that only forgets the approval would leave an access token valid for
 * up to an hour and a refresh token valid for a month, while telling the user
 * they are disconnected — which is strictly worse than having no button at all,
 * because it converts a real risk into a false sense of safety.
 */
final class ConnectedAppsControllerTest extends WebTestCase
{
    private const string PAGE = '/user-profile/connected-apps';

    private const string REVOKE_PATH = '/user-profile/connected-apps/revoke';

    public function testAnonymousVisitorIsSentToLogin(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::PAGE);

        self::assertResponseRedirects('/login');
    }

    /**
     * An account that has connected nothing still gets the page (it is also
     * where personal access tokens are listed), it just has nothing to show.
     */
    public function testThePageRendersWithoutAnyConnection(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_2_EMAIL);

        $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Zatím nemáte propojenou žádnou aplikaci');
    }

    /**
     * After an approval the app appears with the Czech description of what it
     * may do — the same wording the consent screen used, re-derived rather than
     * stored as prose.
     */
    public function testAnApprovedApplicationIsListed(): void
    {
        $browser = self::createClient();
        self::connect($browser, [McpScope::TemplatesRead->value]);

        $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();

        $page = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('mcp-test-public', $page);
        self::assertStringContainsString('Prohlížet vaše projekty a šablony', $page);
    }

    /**
     * A user's own personal access tokens are listed here too — the page has to
     * be a COMPLETE answer to "what can reach my projects?", and which of the
     * two credential mechanisms an assistant happened to use is not something a
     * user should have to know before they can disconnect it.
     */
    public function testPersonalAccessTokensAreListedForTheirOwner(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Osobní přístupové tokeny',
            (string) $browser->getResponse()->getContent(),
        );
    }

    /**
     * ⚠️ Revoking has to end the ACCESS, not just the record of it.
     *
     * The access token is a self-contained JWT whose `exp` is an hour out, so
     * nothing about deleting the approval row would stop it — only the
     * `revoked` flag league's own validator reads can, which is why the handler
     * revokes credentials in the same transaction. Asserted at `/_mcp`, where
     * such a token is actually spent: it works, then it does not.
     */
    public function testRevokingKillsALiveAccessToken(): void
    {
        $browser = self::createClient();
        $token = self::connect($browser, [McpScope::TemplatesRead->value]);

        TestingMcpClient::initialize($browser, $token);
        self::assertSame(
            Response::HTTP_OK,
            $browser->getResponse()->getStatusCode(),
            'The token never worked, so this test would prove nothing.',
        );

        self::revoke($browser);

        TestingMcpClient::initialize($browser, $token);
        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $browser->getResponse()->getStatusCode(),
            'A revoked application kept a working access token.',
        );

        // ...and the connection is gone from the page, so the user is not told
        // something different from what is true.
        $browser->request('GET', self::PAGE);
        self::assertSelectorTextContains('body', 'Zatím nemáte propojenou žádnou aplikaci');
    }

    /**
     * Revocation also forgets the decision: reconnecting the same application
     * asks again, rather than silently resuming on the strength of an approval
     * the user has explicitly withdrawn.
     */
    public function testRevokingForgetsTheApproval(): void
    {
        $browser = self::createClient();
        self::connect($browser, [McpScope::TemplatesRead->value]);

        self::revoke($browser);

        $browser->request('GET', '/api/authorize?' . http_build_query(
            TestingOAuthClient::authorizeQuery([McpScope::TemplatesRead->value]),
        ));

        self::assertStringContainsString(
            '/oauth/consent',
            (string) $browser->getResponse()->headers->get('Location'),
            'A revoked application reconnected without asking.',
        );
    }

    /**
     * A GET-triggerable or CSRF-less revoke would let any page on the internet
     * disconnect a user's integrations. Not catastrophic, but it is a state
     * change on someone's account made by someone else.
     */
    public function testAForgedRevokeIsRejected(): void
    {
        $browser = self::createClient();
        self::connect($browser, [McpScope::TemplatesRead->value]);

        $browser->request('POST', self::REVOKE_PATH, [
            '_token' => 'forged',
            'clientIdentifier' => TestingOAuthClient::PUBLIC_CLIENT_ID,
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $browser->getResponse()->getStatusCode());

        $browser->request('GET', self::PAGE);
        self::assertStringContainsString('mcp-test-public', (string) $browser->getResponse()->getContent());
    }

    /**
     * Revocation is scoped to ONE user's connection, not to the client.
     *
     * This is why the bundle's own `CredentialsRevokerInterface` could not be
     * used as it ships: `revokeCredentialsForClient()` would kill every user's
     * tokens for that client, and a connector like claude.ai is a SINGLE
     * `client_id` shared by the whole installation — so one person
     * disconnecting would log everybody out. Both users here connect the same
     * application; only the one who pressed the button loses access.
     */
    public function testRevokingOnlyAffectsTheUserWhoDidIt(): void
    {
        $browser = self::createClient();
        $tokenOfUser1 = self::connect($browser, [McpScope::TemplatesRead->value]);

        $tokenOfUser2 = TestingOAuthClient::accessToken(
            $browser,
            TestDataFixture::USER_2_EMAIL,
            [McpScope::TemplatesRead->value],
        );

        // USER_2 is the one logged in, so this is USER_2's own revoke form.
        self::revoke($browser);

        TestingMcpClient::initialize($browser, $tokenOfUser2);
        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $browser->getResponse()->getStatusCode(),
            'The revoking user kept a working token.',
        );

        TestingMcpClient::initialize($browser, $tokenOfUser1);
        self::assertSame(
            Response::HTTP_OK,
            $browser->getResponse()->getStatusCode(),
            'One user\'s disconnect killed another user\'s token for the same application.',
        );
    }

    /**
     * Logs USER_1 in, approves the test client and returns a working access
     * token minted through the real endpoints.
     *
     * @param list<string> $scopes
     */
    private static function connect(KernelBrowser $browser, array $scopes): string
    {
        TestingOAuthClient::registerPublicClient($browser->getContainer()->get(ClientManagerInterface::class));

        return TestingOAuthClient::accessToken($browser, TestDataFixture::USER_1_EMAIL, $scopes);
    }

    /**
     * Submits the page's own revoke form, so the CSRF token is the real one and
     * the test exercises the markup a user actually clicks.
     */
    private static function revoke(KernelBrowser $browser): void
    {
        $crawler = $browser->request('GET', self::PAGE);

        $browser->submit($crawler->filter('form[action="' . self::REVOKE_PATH . '"]')->form());
    }
}
