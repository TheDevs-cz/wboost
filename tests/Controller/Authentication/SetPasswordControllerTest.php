<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Authentication;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\PasswordResetToken;
use WBoost\Web\Repository\PasswordResetTokenRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

final class SetPasswordControllerTest extends WebTestCase
{
    public function testInvalidTokenShowsExpiredPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/set-password/' . Uuid::uuid4()->toString());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h4', 'Odkaz vypršel');
    }

    public function testExpiredTokenShowsExpiredPage(): void
    {
        $browser = self::createClient();
        $token = $this->createToken($browser, TestDataFixture::INVITED_USER_EMAIL, '-1 hour');

        $browser->request('GET', '/set-password/' . $token);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h4', 'Odkaz vypršel');
    }

    public function testInvitationTokenShowsWelcomeCopy(): void
    {
        $browser = self::createClient();
        $token = $this->createToken($browser, TestDataFixture::INVITED_USER_EMAIL);

        $browser->request('GET', '/set-password/' . $token);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h4', 'Vítejte ve WBoost');
    }

    public function testSettingPasswordActivatesAccountAndLogsIn(): void
    {
        $browser = self::createClient();
        $token = $this->createToken($browser, TestDataFixture::INVITED_USER_EMAIL);

        $crawler = $browser->request('GET', '/set-password/' . $token);
        $form = $crawler->filter('form')->form();
        $form['set_password_form[password][first]'] = 'secret123';
        $form['set_password_form[password][second]'] = 'secret123';
        $browser->submit($form);

        $this->assertResponseRedirects('/');

        // The invitee is now activated and persisted.
        $container = self::getContainer();
        $container->get(EntityManagerInterface::class)->clear();
        $user = $container->get(UserRepository::class)->get(TestDataFixture::INVITED_USER_EMAIL);
        self::assertTrue($user->confirmed);
        self::assertNotSame('', $user->password);

        // And they are logged in: a protected page renders instead of redirecting to /login.
        $browser->request('GET', '/edit-profile');
        $this->assertResponseIsSuccessful();
    }

    public function testTokenIsSingleUse(): void
    {
        $browser = self::createClient();
        $token = $this->createToken($browser, TestDataFixture::INVITED_USER_EMAIL);

        $crawler = $browser->request('GET', '/set-password/' . $token);
        $form = $crawler->filter('form')->form();
        $form['set_password_form[password][first]'] = 'secret123';
        $form['set_password_form[password][second]'] = 'secret123';
        $browser->submit($form);
        $this->assertResponseRedirects('/');

        // The token is consumed: visiting the same link again shows the expired page.
        $browser->request('GET', '/set-password/' . $token);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h4', 'Odkaz vypršel');
    }

    private function createToken(KernelBrowser $browser, string $email, string $validUntilModifier = '+7 days'): string
    {
        $container = $browser->getContainer();

        $user = $container->get(UserRepository::class)->get($email);
        $clock = $container->get(ClockInterface::class);
        $id = $container->get(ProvideIdentity::class)->next();

        $token = new PasswordResetToken(
            $id,
            $user,
            $clock->now(),
            $clock->now()->modify($validUntilModifier),
        );

        $container->get(PasswordResetTokenRepository::class)->save($token);
        $container->get(EntityManagerInterface::class)->flush();

        return $id->toString();
    }
}
