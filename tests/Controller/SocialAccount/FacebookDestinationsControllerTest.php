<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\SocialAccount;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeMetaGraphApi;
use WBoost\Web\Tests\TestingLogin;

final class FacebookDestinationsControllerTest extends WebTestCase
{
    public function testConnectedUserGetsPagesWithInstagramInfo(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/social/facebook/destinations');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertIsArray($data);
        self::assertTrue($data['connected'] ?? false);

        $pages = $data['pages'] ?? null;
        self::assertIsArray($pages);
        self::assertCount(2, $pages);

        $pageWithoutInstagram = $pages[0];
        self::assertIsArray($pageWithoutInstagram);
        self::assertSame(FakeMetaGraphApi::PAGE_WITHOUT_IG_ID, $pageWithoutInstagram['id'] ?? null);
        self::assertArrayHasKey('instagram', $pageWithoutInstagram);
        self::assertNull($pageWithoutInstagram['instagram']);

        $pageWithInstagram = $pages[1];
        self::assertIsArray($pageWithInstagram);
        $instagram = $pageWithInstagram['instagram'] ?? null;
        self::assertIsArray($instagram);
        self::assertSame('brand.two', $instagram['username'] ?? null);

        // Page access tokens must NEVER be serialized to the browser.
        self::assertStringNotContainsString('page-token', (string) $client->getResponse()->getContent());
    }

    public function testUserWithoutConnectionGetsConnectedFalse(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('GET', '/social/facebook/destinations');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertIsArray($data);
        self::assertFalse($data['connected'] ?? true);
    }

    public function testRequiresAuthentication(): void
    {
        $client = self::createClient();

        $client->request('GET', '/social/facebook/destinations');

        self::assertResponseRedirects('http://localhost/login');
    }
}
