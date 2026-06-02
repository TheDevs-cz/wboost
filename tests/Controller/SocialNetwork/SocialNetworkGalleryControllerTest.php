<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\SocialNetwork;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * The standalone gallery management page renders the Project:ImageGallery
 * component full-page and is gated to project editors.
 */
final class SocialNetworkGalleryControllerTest extends WebTestCase
{
    private const string URL = '/project/' . TestDataFixture::PROJECT_1_ID . '/social-network-gallery';

    public function testRedirectsGuestToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', self::URL);

        self::assertResponseRedirects();
    }

    public function testRendersForProjectOwner(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        // The gallery Live Component is rendered standalone (no modal chrome).
        self::assertSelectorExists('[data-controller~="image-gallery"]');
    }

    public function testForbiddenForNonEditor(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('GET', self::URL);

        self::assertResponseStatusCodeSame(403);
    }
}
