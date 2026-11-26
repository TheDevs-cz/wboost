<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Instagram;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\ProjectSocialAccount;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\InstagramTokenExpired;
use WBoost\Web\Services\Instagram\InstagramApiInterface;
use WBoost\Web\Services\Meta\MetaSignedRequest;
use WBoost\Web\Services\Security\TokenCrypto;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeInstagramApi;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\SocialPlatform;

/**
 * The project's Instagram connection: the "Facebook a Instagram" page, the
 * connect round trip (state, permissions, project_edit), disconnect, Meta's
 * deauthorize / data-deletion callbacks and the token refresh command.
 */
final class ProjectInstagramConnectionTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string PROJECT = TestDataFixture::PROJECT_1_ID;

    private function api(): FakeInstagramApi
    {
        $api = self::getContainer()->get(InstagramApiInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeInstagramApi::class, $api);

        return $api;
    }

    private function account(): null|ProjectSocialAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(ProjectSocialAccount::class)->findOneBy([
            'project' => Uuid::fromString(self::PROJECT),
            'platform' => SocialPlatform::Instagram,
        ]);
    }

    private function seedAccount(string $expiresIn = '+50 days', string $issuedAgo = '-10 days'): ProjectSocialAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $project = $entityManager->find(Project::class, Uuid::fromString(self::PROJECT));
        self::assertInstanceOf(Project::class, $project);

        $account = new ProjectSocialAccount(
            Uuid::uuid7(),
            $project,
            SocialPlatform::Instagram,
            FakeInstagramApi::IG_USER_ID,
            FakeInstagramApi::APP_SCOPED_ID,
            FakeInstagramApi::USERNAME,
            null,
            'BUSINESS',
            self::getContainer()->get(TokenCrypto::class)->encrypt('stored-token'),
            new DateTimeImmutable($expiresIn),
            ['instagram_business_basic', 'instagram_business_content_publish'],
            null,
            new DateTimeImmutable($issuedAgo),
        );
        $entityManager->persist($account);
        $entityManager->flush();

        return $account;
    }

    /**
     * Start the flow and return the state Instagram would echo back.
     */
    private function startConnect(KernelBrowser $client): string
    {
        $client->request('GET', '/project/' . self::PROJECT . '/instagram/connect');
        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('http://localhost/oauth/instagram/check', $query['redirect_uri'] ?? null);
        self::assertSame('instagram_business_basic,instagram_business_content_publish', $query['scope'] ?? null);
        $state = $query['state'] ?? null;
        self::assertIsString($state);

        return $state;
    }

    public function testPageOffersConnectToProjectEditor(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/project/' . self::PROJECT . '/social');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('a[href="/project/%s/instagram/connect"]', self::PROJECT));
        self::assertSelectorTextContains('#socFbH', 'Facebook');
    }

    public function testSharedMemberSeesStatusButCannotConnect(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::SHARED_USER_EMAIL);

        $client->request('GET', '/project/' . self::PROJECT . '/social');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(sprintf('a[href="/project/%s/instagram/connect"]', self::PROJECT));
        self::assertSelectorTextContains('.pd-st-ro', 'Propojí správce projektu');

        $client->request('GET', '/project/' . self::PROJECT . '/instagram/connect');
        self::assertResponseStatusCodeSame(403);
    }

    public function testConnectRoundTripStoresTheProjectAccount(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $state = $this->startConnect($client);
        $client->request('GET', '/oauth/instagram/check?code=abc&state=' . $state);

        self::assertResponseRedirects('/project/' . self::PROJECT . '/social');
        self::assertSame('abc', $this->api()->callsTo('exchangeCode')[0]['args']['code']);

        $account = $this->account();
        self::assertInstanceOf(ProjectSocialAccount::class, $account);
        self::assertSame(FakeInstagramApi::IG_USER_ID, $account->accountId);
        self::assertSame(FakeInstagramApi::APP_SCOPED_ID, $account->accountUserId);
        self::assertSame(FakeInstagramApi::USERNAME, $account->username);
        self::assertNotSame('long-short-abc', $account->accessToken, 'the token is stored encrypted');
        self::assertSame('long-short-abc', self::getContainer()->get(TokenCrypto::class)->decrypt($account->accessToken));
        self::assertNotNull($account->connectedBy);
        self::assertSame(TestDataFixture::USER_1_EMAIL, $account->connectedBy->email);

        $client->followRedirect();
        self::assertSelectorTextContains('[data-instagram-account]', '@' . FakeInstagramApi::USERNAME);
    }

    public function testReconnectReplacesTheAccountInPlace(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $existing = $this->seedAccount();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            'UPDATE project_social_account SET needs_reconnect = true WHERE id = ?',
            [$existing->id->toString()],
        );

        $state = $this->startConnect($client);
        $client->request('GET', '/oauth/instagram/check?code=xyz&state=' . $state);

        $account = $this->account();
        self::assertInstanceOf(ProjectSocialAccount::class, $account);
        self::assertTrue($account->id->equals($existing->id));
        self::assertFalse($account->needsReconnect);
        self::assertSame('long-short-xyz', self::getContainer()->get(TokenCrypto::class)->decrypt($account->accessToken));
    }

    public function testForgedOrReusedStateIsRefused(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $state = $this->startConnect($client);
        $client->request('GET', '/oauth/instagram/check?code=abc&state=not-' . $state);
        self::assertResponseRedirects('/dashboard');

        // The state was single-use: the real one no longer works either.
        $client->request('GET', '/oauth/instagram/check?code=abc&state=' . $state);
        self::assertResponseRedirects('/dashboard');

        self::assertSame([], $this->api()->callsTo('exchangeCode'));
        self::assertNull($this->account());
    }

    public function testCancelledConsentStoresNothing(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $state = $this->startConnect($client);
        $client->request('GET', '/oauth/instagram/check?error=access_denied&error_reason=user_denied&state=' . $state);

        self::assertResponseRedirects('/project/' . self::PROJECT . '/social');
        self::assertNull($this->account());
    }

    public function testMissingPublishPermissionIsRefused(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $state = $this->startConnect($client);
        // Configure the fake for the NEXT request (same kernel, no reboot).
        $client->disableReboot();
        $this->api()->grantedPermissions = ['instagram_business_basic'];

        $client->request('GET', '/oauth/instagram/check?code=abc&state=' . $state);

        self::assertResponseRedirects('/project/' . self::PROJECT . '/social');
        self::assertNull($this->account());
    }

    public function testDisconnectDropsTheConnection(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $this->seedAccount();

        $crawler = $client->request('GET', '/project/' . self::PROJECT . '/social');
        $client->submit($crawler->filter(sprintf('form[action="/project/%s/instagram/disconnect"]', self::PROJECT))->form());

        self::assertResponseRedirects('/project/' . self::PROJECT . '/social');
        self::assertNull($this->account());
    }

    public function testDeauthorizeAndDataDeletionCallbacksDropConnections(): void
    {
        $client = self::createClient();
        $this->seedAccount();

        // Wrong secret → refused, nothing deleted.
        $client->request('POST', '/oauth/instagram/deauthorize', [
            'signed_request' => MetaSignedRequest::sign(FakeInstagramApi::APP_SCOPED_ID, 'wrong-secret'),
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertNotNull($this->account());

        $client->request('POST', '/oauth/instagram/deauthorize', [
            'signed_request' => MetaSignedRequest::sign(FakeInstagramApi::APP_SCOPED_ID, 'test-ig-app-secret'),
        ]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->account());

        $this->seedAccount();
        $client->request('POST', '/oauth/instagram/data-deletion', [
            'signed_request' => MetaSignedRequest::sign(FakeInstagramApi::IG_USER_ID, 'test-ig-app-secret'),
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsString($data['confirmation_code']);
        self::assertIsString($data['url']);
        self::assertStringContainsString('/oauth/instagram/data-deletion/status', $data['url']);
        self::assertNull($this->account());

        $client->request('GET', '/oauth/instagram/data-deletion/status?code=' . $data['confirmation_code']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.auth-top', 'instagramového');
    }

    public function testRefreshCommandRenewsDueTokensAndFlagsDeadOnes(): void
    {
        $kernel = self::bootKernel();
        $due = $this->seedAccount('+10 days', '-50 days');

        $tester = new CommandTester((new Application($kernel))->find('app:instagram:refresh-tokens'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $account = $this->account();
        self::assertInstanceOf(ProjectSocialAccount::class, $account);
        self::assertTrue($account->id->equals($due->id));
        self::assertSame('refreshed-stored-token', self::getContainer()->get(TokenCrypto::class)->decrypt($account->accessToken));
        self::assertGreaterThan(new DateTimeImmutable('+50 days'), $account->tokenExpiresAt);

        // Fresh token now: the next run leaves it alone.
        $tester->execute([]);
        self::assertCount(1, $this->api()->callsTo('refreshToken'));

        // A token Instagram rejects flags the connection instead of failing the run.
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            "UPDATE project_social_account SET token_issued_at = NOW() - INTERVAL '50 days', token_expires_at = NOW() + INTERVAL '5 days'",
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->api()->throwOnRefresh = new InstagramTokenExpired('Session has expired.', 190);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $account = $this->account();
        self::assertInstanceOf(ProjectSocialAccount::class, $account);
        self::assertTrue($account->needsReconnect);
    }

    public function testFillPagePassesTheProjectAccountToTheFiller(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::SHARED_USER_EMAIL);
        $this->seedAccount();

        $client->request('GET', '/template-variant/' . TestDataFixture::GROUPED_PRESET_VARIANT_ID . '/export');

        self::assertResponseIsSuccessful();
        // The deferred Live component receives the props in its placeholder.
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('&quot;instagramAvailable&quot;:true', $html);
        self::assertStringContainsString('&quot;instagramAccount&quot;:&quot;' . FakeInstagramApi::USERNAME . '&quot;', $html);
    }

    public function testFillerRendersInstagramPublishChrome(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $variants = self::getContainer()->get(EntityManagerInterface::class)->getRepository(TemplateVariant::class);

        $render = fn (string $variantId, string $account): string => (string) $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: ['variant' => $variants->find(Uuid::fromString($variantId)), 'instagramAvailable' => true, 'instagramAccount' => $account],
            client: $client,
        )->render();

        $html = $render(TestDataFixture::GROUPED_PRESET_VARIANT_ID, '');
        self::assertStringContainsString('data-variant-publish-platform-param="instagram"', $html);
        self::assertStringContainsString('data-variant-publish-instagram-account-value=""', $html);
        self::assertStringContainsString(sprintf('data-variant-publish-social-url-value="/project/%s/social"', self::PROJECT), $html);
        self::assertStringContainsString('data-variant-publish-instagram-story-value="false"', $html);
        // No personal Facebook link in this render → Facebook keeps the "coming soon" door.
        self::assertStringContainsString('data-bs-target="#social-publish-fake-door"', $html);

        $html = $render(TestDataFixture::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID, FakeInstagramApi::USERNAME);
        self::assertStringContainsString('data-variant-publish-instagram-account-value="' . FakeInstagramApi::USERNAME . '"', $html);
        self::assertStringContainsString('data-variant-publish-instagram-story-value="true"', $html);
        self::assertStringContainsString('Publikovat na Instagram (story)', $html);
    }
}
