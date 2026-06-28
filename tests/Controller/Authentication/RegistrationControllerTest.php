<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Authentication;

use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Repository\RegistrationRequestRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

final class RegistrationControllerTest extends WebTestCase
{
    use MailerAssertionsTrait;

    public function testResponseIsOk(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/registration');

        $this->assertResponseIsSuccessful();
    }

    public function testRedirectLoggedUsersToHomepage(): void
    {
        $browser = self::createClient();

        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', '/registration');

        $this->assertResponseRedirects('/');
    }

    public function testNewRequestIsPersistedAndAdminsNotified(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/registration');
        $form = $crawler->selectButton('Požádat o registraci')->form();
        $form['request_access_form[email]'] = 'newcomer@test.cz';
        $browser->submit($form);

        $this->assertResponseRedirects('/login');
        self::assertEmailCount(1);

        $request = self::getContainer()->get(RegistrationRequestRepository::class)->findPendingByEmail('newcomer@test.cz');
        self::assertNotNull($request);
    }

    public function testDuplicatePendingShowsInfoMessage(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/registration');
        $form = $crawler->selectButton('Požádat o registraci')->form();
        $form['request_access_form[email]'] = TestDataFixture::REGISTRATION_REQUEST_PENDING_EMAIL;
        $browser->submit($form);

        $this->assertResponseRedirects('/registration');
        self::assertEmailCount(0);
    }

    public function testAlreadyRegisteredIsNeutralSuccess(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/registration');
        $form = $crawler->selectButton('Požádat o registraci')->form();
        $form['request_access_form[email]'] = TestDataFixture::USER_1_EMAIL;
        $browser->submit($form);

        // Neutral success (no account-enumeration leak), no request created, no mail.
        $this->assertResponseRedirects('/login');
        self::assertEmailCount(0);
    }
}
