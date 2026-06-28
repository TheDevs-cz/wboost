<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

final class AdminRegistrationRequestsControllerTest extends WebTestCase
{
    public function testForbiddenForNonAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', '/admin/registration-requests');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testListsPendingRequestsForAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $browser->request('GET', '/admin/registration-requests');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', TestDataFixture::REGISTRATION_REQUEST_PENDING_EMAIL);
    }
}
