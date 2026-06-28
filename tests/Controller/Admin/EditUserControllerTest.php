<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\User;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

final class EditUserControllerTest extends WebTestCase
{
    public function testForbiddenForNonAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', '/admin/users/' . TestDataFixture::USER_2_ID . '/edit');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testEditUpdatesUser(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $browser->request('GET', '/admin/users/' . TestDataFixture::INVITED_USER_ID . '/edit');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Uložit')->form();
        $form['edit_user_form[name]'] = 'Admin Edited';
        $form['edit_user_form[role]'] = User::ROLE_ADMIN;
        $browser->submit($form);

        $this->assertResponseRedirects('/admin/users');

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $user = self::getContainer()->get(UserRepository::class)->getById(Uuid::fromString(TestDataFixture::INVITED_USER_ID));
        self::assertSame('Admin Edited', $user->name);
        self::assertContains(User::ROLE_ADMIN, $user->getRoles());
    }
}
