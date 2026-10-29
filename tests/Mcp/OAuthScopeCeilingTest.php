<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp;

use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\User;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Tests\TestingOAuthClient;

/**
 * The admin-set ceiling on OAuth grants ({@see User::$mcpScopes}). USER_2 sits
 * at the read + export default; USER_1 is given every scope by the fixture.
 *
 * The 401 asks every client for ALL scopes, so this ceiling — not the client —
 * is what decides what a sign-in can reach. Each of the three places it is
 * applied has a test: the consent screen, the issued token, and the live
 * request.
 */
final class OAuthScopeCeilingTest extends WebTestCase
{
    public function testTheConsentScreenOnlyAsksForScopesInsideTheCeiling(): void
    {
        $client = self::createClient();
        self::registerPublicClient($client);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('GET', '/api/authorize?' . http_build_query(TestingOAuthClient::authorizeQuery(McpScope::values())));
        $page = $client->followRedirect()->html();

        self::assertStringContainsString('Stahovat hotové exporty', $page);
        self::assertStringNotContainsString('Vytvářet a přepisovat šablony', $page, 'Design was offered beyond the ceiling.');
        self::assertStringNotContainsString('Nahrávat obrázky do galerie', $page, 'Gallery upload was offered beyond the ceiling.');
    }

    /**
     * Enforcement, not just display: league's authorization code would still
     * carry everything the client asked for without CapScopesToUserCeilingListener.
     */
    public function testTheIssuedTokenIsCappedToTheCeiling(): void
    {
        $client = self::createClient();

        $token = self::oauthToken($client, TestDataFixture::USER_2_EMAIL, McpScope::values());

        self::assertProbe(
            $client,
            $token,
            sprintf('%s|%s,%s', TestDataFixture::USER_2_EMAIL, McpScope::TemplatesExport->value, McpScope::TemplatesRead->value),
        );
    }

    /**
     * A scope above the ceiling falls back to what it implies AND the ceiling
     * allows: design implies read, and read is all USER_2 gets of it.
     */
    public function testAScopeAboveTheCeilingFallsBackToWhatItImplies(): void
    {
        $client = self::createClient();

        $token = self::oauthToken($client, TestDataFixture::USER_2_EMAIL, [McpScope::TemplatesDesign->value]);
        self::assertProbe($client, $token, TestDataFixture::USER_2_EMAIL . '|' . McpScope::TemplatesRead->value);
    }

    /**
     * A request the ceiling leaves NO MCP scope of is refused outright rather
     * than answered with a token that connects to nothing. `gallery:write`
     * implies nothing, so there is no fallback for it.
     */
    public function testARequestTheCeilingLeavesNothingOfIsDenied(): void
    {
        $client = self::createClient();
        self::registerPublicClient($client);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('GET', '/api/authorize?' . http_build_query(TestingOAuthClient::authorizeQuery([McpScope::GalleryWrite->value])));

        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('/oauth/consent', $location);
        self::assertStringContainsString('error=access_denied', $location);
    }

    /**
     * Narrowing the ceiling bites on the NEXT request, not when the token
     * expires — the authenticator re-applies it every time.
     */
    public function testNarrowingTheCeilingAppliesToAnExistingToken(): void
    {
        $client = self::createClient();

        $token = self::oauthToken($client, TestDataFixture::USER_1_EMAIL, [McpScope::TemplatesDesign->value]);

        $user = $client->getContainer()->get(UserRepository::class)->getById(Uuid::fromString(TestDataFixture::USER_1_ID));
        $user->changeMcpScopes([McpScope::TemplatesRead->value]);
        $client->getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertProbe($client, $token, TestDataFixture::USER_1_EMAIL . '|' . McpScope::TemplatesRead->value);
    }

    /**
     * @param list<string> $scopes
     */
    private static function oauthToken(KernelBrowser $client, string $email, array $scopes): string
    {
        self::registerPublicClient($client);

        $token = TestingOAuthClient::accessToken($client, $email, $scopes);

        $client->getCookieJar()->clear();

        return $token;
    }

    /**
     * The probe reports `email|sorted,expanded,scopes` from inside the request
     * the authenticator served. The trailing quote pins the END of the list, so
     * a scope that leaked past the ceiling cannot hide behind a prefix match.
     */
    private static function assertProbe(KernelBrowser $client, string $token, string $expected): void
    {
        $sessionId = TestingMcpClient::connect($client, $token);
        TestingMcpClient::request($client, 'tools/call', ['name' => 'auth_probe', 'arguments' => []], $sessionId, $token);

        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString($expected . '"', (string) $response->getContent());
    }

    private static function registerPublicClient(KernelBrowser $client): void
    {
        TestingOAuthClient::registerPublicClient($client->getContainer()->get(ClientManagerInterface::class));
    }
}
