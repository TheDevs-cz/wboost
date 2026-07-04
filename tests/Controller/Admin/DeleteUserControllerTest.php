<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

final class DeleteUserControllerTest extends WebTestCase
{
    public function testForbiddenForNonAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', '/admin/users/' . TestDataFixture::USER_2_ID . '/delete');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminDeletesUser(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $browser->request('GET', '/admin/users/' . TestDataFixture::INVITED_USER_ID . '/delete');

        $this->assertResponseRedirects('/admin/users');

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $user = self::getContainer()->get(UserRepository::class)->findByEmailOrNull(TestDataFixture::INVITED_USER_EMAIL);
        self::assertNull($user);
    }

    public function testDeleteButtonRendersForOthersButNotSelf(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $browser->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful();

        $content = (string) $browser->getResponse()->getContent();

        // Delete action + interpolated confirmation text for another user.
        self::assertStringContainsString('/admin/users/' . TestDataFixture::INVITED_USER_ID . '/delete', $content);
        self::assertStringContainsString('smazat uživatele ' . TestDataFixture::INVITED_USER_EMAIL, $content);

        // No delete action for the current admin's own row.
        self::assertStringNotContainsString('/admin/users/' . TestDataFixture::ADMIN_USER_ID . '/delete', $content);
    }

    public function testAdminCannotDeleteSelf(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $browser->request('GET', '/admin/users/' . TestDataFixture::ADMIN_USER_ID . '/delete');

        $this->assertResponseRedirects('/admin/users');

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $user = self::getContainer()->get(UserRepository::class)->findByEmailOrNull(TestDataFixture::ADMIN_USER_EMAIL);
        self::assertNotNull($user);
    }
}
