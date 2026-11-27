<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\FacebookPage;

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
use WBoost\Web\Exceptions\FacebookPageTokenExpired;
use WBoost\Web\Services\FacebookPage\FacebookPageApiInterface;
use WBoost\Web\Services\Meta\MetaSignedRequest;
use WBoost\Web\Services\Security\TokenCrypto;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeFacebookPageApi;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\FacebookPage;
use WBoost\Web\Value\SocialPlatform;

/**
 * The project's Facebook Page connection: the project page box, the connect
 * round trip (state, shared Pages, picker, project_edit), disconnect, Meta's
 * callbacks and the nightly token check.
 */
final class ProjectFacebookPageConnectionTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string PROJECT = TestDataFixture::PROJECT_1_ID;

    private function api(): FakeFacebookPageApi
    {
        $api = self::getContainer()->get(FacebookPageApiInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeFacebookPageApi::class, $api);

        return $api;
    }

    private function account(): null|ProjectSocialAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(ProjectSocialAccount::class)->findOneBy([
            'project' => Uuid::fromString(self::PROJECT),
            'platform' => SocialPlatform::Facebook,
        ]);
    }

    private function seedAccount(): ProjectSocialAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $project = $entityManager->find(Project::class, Uuid::fromString(self::PROJECT));
        self::assertInstanceOf(Project::class, $project);

        $account = new ProjectSocialAccount(
            Uuid::uuid7(),
            $project,
            SocialPlatform::Facebook,
            FakeFacebookPageApi::PAGE_ID,
            FakeFacebookPageApi::OWNER_ID,
            null,
            FakeFacebookPageApi::PAGE_NAME,
            null,
            self::getContainer()->get(TokenCrypto::class)->encrypt('stored-page-token'),
            null,
            [],
            null,
            new DateTimeImmutable('-10 days'),
        );
        $entityManager->persist($account);
        $entityManager->flush();

        return $account;
    }

    private function startConnect(KernelBrowser $client): string
    {
        $client->request('GET', '/project/' . self::PROJECT . '/facebook/connect');
        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://www.facebook.com/dialog/oauth?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('http://localhost/oauth/facebook-page/check', $query['redirect_uri'] ?? null);
        self::assertSame('test-fb-pages-config-id', $query['config_id'] ?? null);
        $state = $query['state'] ?? null;
        self::assertIsString($state);

        return $state;
    }

    public function testPageOffersConnectToProjectEditorOnly(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/project/' . self::PROJECT . '/social');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('a[href="/project/%s/facebook/connect"]', self::PROJECT));

        TestingLogin::logInAsUser($client, TestDataFixture::SHARED_USER_EMAIL);
        $client->request('GET', '/project/' . self::PROJECT . '/social');
        self::assertSelectorNotExists(sprintf('a[href="/project/%s/facebook/connect"]', self::PROJECT));
        $client->request('GET', '/project/' . self::PROJECT . '/facebook/connect');
        self::assertResponseStatusCodeSame(403);
    }

    public function testConnectRoundTripStoresThePageAndItsToken(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $state = $this->startConnect($client);
        $client->request('GET', '/oauth/facebook-page/check?code=abc&state=' . $state);

        self::assertResponseRedirects('/project/' . self::PROJECT . '/social');
        self::assertSame('abc', $this->api()->callsTo('exchangeCode')[0]['args']['code']);
        self::assertSame('bisu-abc', $this->api()->callsTo('pages')[0]['args']['accessToken']);

        $account = $this->account();
        self::assertInstanceOf(ProjectSocialAccount::class, $account);
        self::assertSame(FakeFacebookPageApi::PAGE_ID, $account->accountId);
        self::assertSame(FakeFacebookPageApi::OWNER_ID, $account->accountUserId);
        self::assertSame(FakeFacebookPageApi::PAGE_NAME, $account->name);
        self::assertNull($account->tokenExpiresAt);
        self::assertSame(FakeFacebookPageApi::PAGE_TOKEN, self::getContainer()->get(TokenCrypto::class)->decrypt($account->accessToken), 'the PAGE token is stored, encrypted');

        $client->followRedirect();
        self::assertSelectorTextContains('[data-facebook-page]', FakeFacebookPageApi::PAGE_NAME);
    }

    public function testForgedStateIsRefused(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $state = $this->startConnect($client);
        $client->request('GET', '/oauth/facebook-page/check?code=abc&state=not-' . $state);
        self::assertResponseRedirects('/dashboard');
        self::assertSame([], $this->api()->callsTo('exchangeCode'));
        self::assertNull($this->account());
    }

    public function testNoSharedPageIsRefused(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $state = $this->startConnect($client);
        $client->disableReboot();
        $this->api()->pages = [];

        $client->request('GET', '/oauth/facebook-page/check?code=abc&state=' . $state);

        self::assertResponseRedirects('/project/' . self::PROJECT . '/social');
        self::assertNull($this->account());
    }

    public function testSeveralSharedPagesLetTheEditorPickOne(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $state = $this->startConnect($client);
        $client->disableReboot();
        $this->api()->pages = [
            new FacebookPage('100000000000002', 'Pekárna Test', 'page-token-2', null, null),
            new FacebookPage(FakeFacebookPageApi::PAGE_ID, FakeFacebookPageApi::PAGE_NAME, FakeFacebookPageApi::PAGE_TOKEN, null, null),
        ];

        $client->request('GET', '/oauth/facebook-page/check?code=abc&state=' . $state);
        self::assertResponseRedirects('/project/' . self::PROJECT . '/facebook/pick');
        self::assertNull($this->account());

        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('page-token-2', (string) $client->getResponse()->getContent());
        $client->submit($crawler->filter('[data-facebook-pick="Pekárna Test"] form')->form());

        self::assertResponseRedirects('/project/' . self::PROJECT . '/social');
        $account = $this->account();
        self::assertInstanceOf(ProjectSocialAccount::class, $account);
        self::assertSame('100000000000002', $account->accountId);
        self::assertSame('Pekárna Test', $account->name);
        self::assertSame('page-token-2', self::getContainer()->get(TokenCrypto::class)->decrypt($account->accessToken));

        $client->request('GET', '/project/' . self::PROJECT . '/facebook/pick');
        self::assertResponseRedirects('/project/' . self::PROJECT . '/social');
    }

    public function testDisconnectDropsTheConnection(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $this->seedAccount();

        $crawler = $client->request('GET', '/project/' . self::PROJECT . '/social');
        $client->submit($crawler->filter(sprintf('form[action="/project/%s/facebook/disconnect"]', self::PROJECT))->form());

        self::assertResponseRedirects('/project/' . self::PROJECT . '/social');
        self::assertNull($this->account());
    }

    public function testDeauthorizeCallbackDropsFacebookRowsToo(): void
    {
        $client = self::createClient();
        $this->seedAccount();

        $client->request('POST', '/oauth/instagram/deauthorize', [
            'signed_request' => MetaSignedRequest::sign(FakeFacebookPageApi::OWNER_ID, 'test-ig-app-secret'),
        ]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->account());
    }

    public function testCheckCommandFlagsARejectedPageToken(): void
    {
        $kernel = self::bootKernel();
        $this->seedAccount();

        $tester = new CommandTester((new Application($kernel))->find('app:instagram:check-tokens'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertFalse($this->account()?->needsReconnect);
        self::assertSame('stored-page-token', $this->api()->callsTo('checkPageToken')[0]['args']['pageAccessToken']);

        $this->api()->throwOnCheck = new FacebookPageTokenExpired('Session has expired.', 190);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertTrue($this->account()?->needsReconnect);
    }

    public function testFillerPublishesToTheProjectPage(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $variants = self::getContainer()->get(EntityManagerInterface::class)->getRepository(TemplateVariant::class);

        $html = (string) $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: ['variant' => $variants->find(Uuid::fromString(TestDataFixture::GROUPED_PRESET_VARIANT_ID)), 'facebookAvailable' => true, 'facebookPage' => FakeFacebookPageApi::PAGE_NAME],
            client: $client,
        )->render();

        self::assertStringContainsString('data-variant-publish-platform-param="facebook"', $html);
        self::assertStringContainsString('data-variant-publish-facebook-project-value="true"', $html);
        self::assertStringContainsString('data-variant-publish-facebook-page-value="' . FakeFacebookPageApi::PAGE_NAME . '"', $html);
    }

    public function testFillPagePassesTheProjectPageToTheFiller(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::SHARED_USER_EMAIL);
        $this->seedAccount();

        $client->request('GET', '/template-variant/' . TestDataFixture::GROUPED_PRESET_VARIANT_ID . '/export');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('&quot;facebookAvailable&quot;:true', $html);
        // (JSON-escaped: the name's non-ASCII letters become \uXXXX.)
        self::assertStringContainsString('&quot;facebookPage&quot;:&quot;Kav', $html);
    }
}
