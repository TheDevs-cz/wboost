<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Authentication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pins the security wiring for the Facebook OAuth surface: the login start
 * must be reachable anonymously (it's in the public access_control list) and
 * must hand the visitor over to Facebook's consent dialog.
 */
final class FacebookLoginAccessTest extends WebTestCase
{
    public function testLoginStartIsPublicAndRedirectsToFacebook(): void
    {
        $client = self::createClient();

        $client->request('GET', '/oauth/facebook/login');

        self::assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('facebook.com', $location);
        self::assertStringContainsString('scope=public_profile%2Cemail', $location);
    }

    public function testConnectStartRequiresAuthentication(): void
    {
        $client = self::createClient();

        $client->request('GET', '/oauth/facebook/connect');

        // Public path in access_control, but the controller itself enforces
        // IS_AUTHENTICATED_FULLY → the form_login entry point redirects.
        self::assertResponseRedirects('http://localhost/login');
    }

    public function testLoginPageShowsFacebookButton(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/oauth/facebook/login"]'));
    }
}
