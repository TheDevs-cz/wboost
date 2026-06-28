<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

final class AdminUsersControllerTest extends WebTestCase
{
    public function testAnonymousRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/users');

        $this->assertResponseRedirects('/login');
    }

    public function testForbiddenForNonAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', '/admin/users');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testListsUsersForAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $browser->request('GET', '/admin/users');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', TestDataFixture::INVITED_USER_EMAIL);
    }
}
