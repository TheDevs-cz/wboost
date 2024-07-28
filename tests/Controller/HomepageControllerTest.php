<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\TestingLogin;

final class HomepageControllerTest extends WebTestCase
{
    public function testAnonymousUserWillBeRedirectedToLogin(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/');

        $this->assertResponseRedirects('/login');
    }

    public function testLoggedUserWillBeRedirected(): void
    {
        $browser = self::createClient();

        TestingLogin::logInAsUser($browser, 'user1@test.cz');

        $browser->request('GET', '/');

        $this->assertResponseRedirects('/projects');
    }
}
