<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

final class ResendInvitationControllerTest extends WebTestCase
{
    use MailerAssertionsTrait;

    public function testForbiddenForNonAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', '/admin/users/' . TestDataFixture::INVITED_USER_ID . '/resend-invitation');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testResendSendsInvitationForAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $browser->request('GET', '/admin/users/' . TestDataFixture::INVITED_USER_ID . '/resend-invitation');

        $this->assertResponseRedirects('/admin/users');
        self::assertEmailCount(1);
    }
}
