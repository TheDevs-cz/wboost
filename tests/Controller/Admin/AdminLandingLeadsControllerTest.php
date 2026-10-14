<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

final class AdminLandingLeadsControllerTest extends WebTestCase
{
    public function testForbiddenForNonAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', '/admin/landing-leads');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testListsLeadsForAdmin(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/poptavka', [
            'source' => 'klienti',
            'name' => 'Obec Lhota',
            'email' => 'starosta@example.com',
            'interests' => 'Zpravodaj',
        ], server: ['HTTP_ACCEPT' => 'application/json']);
        $this->assertResponseStatusCodeSame(201);

        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);
        $browser->request('GET', '/admin/landing-leads');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'starosta@example.com');
        $this->assertSelectorTextContains('body', 'Zpravodaj');
        $this->assertSelectorTextContains('body', 'Klienti (/klienti)');
    }

    public function testEmptyStateForAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $browser->request('GET', '/admin/landing-leads');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Zatím nepřišla žádná poptávka');
    }
}
