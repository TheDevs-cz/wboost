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

    /**
     * Registration requests are the users page's "Žádosti" chip, so the
     * sidebar carries their pending count on Uživatelé instead of a second
     * item (the requests page itself stays routable).
     */
    public function testSidebarPutsThePendingRequestCountOnUsers(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $browser->request('GET', '/admin/registration-requests');

        $this->assertResponseIsSuccessful();
        $admin = $crawler->filter('.sb nav[aria-label="Administrace"]');
        self::assertCount(0, $admin->filter('a[href="/admin/registration-requests"]'));
        $users = $admin->filter('a.nav-i[href="/admin/users"]');
        self::assertSame('page', $users->attr('aria-current'));
        self::assertSame('1', trim($users->filter('.count')->text()));
    }
}
