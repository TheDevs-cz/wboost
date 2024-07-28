<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Authentication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\TestingLogin;

final class RegistrationControllerTest extends WebTestCase
{
    public function testResponseIsOk(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/registration');

        $this->assertResponseIsSuccessful();
    }


    public function testRedirectLoggedUsersToHomepage(): void
    {
        $browser = self::createClient();

        TestingLogin::logInAsUser($browser, 'user1@test.cz');

        $browser->request('GET', '/registration');

        $this->assertResponseRedirects('/');
    }
}
