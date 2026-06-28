<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

final class InviteUserControllerTest extends WebTestCase
{
    use MailerAssertionsTrait;

    public function testForbiddenForNonAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', '/admin/users/invite');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testPageRendersForAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $browser->request('GET', '/admin/users/invite');

        $this->assertResponseIsSuccessful();
    }

    public function testInviteHappyPath(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $browser->request('GET', '/admin/users/invite');
        $form = $crawler->selectButton('Odeslat pozvánku')->form();
        $form['invite_user_form[email]'] = 'invited-via-form@test.cz';
        $form['invite_user_form[name]'] = 'Via Form';
        $browser->submit($form);

        $this->assertResponseRedirects('/admin/users');
        self::assertEmailCount(1);

        $user = self::getContainer()->get(UserRepository::class)->findByEmailOrNull('invited-via-form@test.cz');
        self::assertNotNull($user);
        self::assertFalse($user->confirmed);
    }

    public function testInviteDuplicateConfirmedShowsError(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $browser->request('GET', '/admin/users/invite');
        $form = $crawler->selectButton('Odeslat pozvánku')->form();
        $form['invite_user_form[email]'] = TestDataFixture::USER_1_EMAIL;
        $browser->submit($form);

        $this->assertResponseRedirects('/admin/users/invite');
    }

    public function testEmailIsPrefilledFromQuery(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $browser->request('GET', '/admin/users/invite?email=prefilled@test.cz');

        self::assertSame('prefilled@test.cz', $crawler->filter('#invite_user_form_email')->attr('value'));
    }
}
