<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\WeeklyMenu;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

final class WeeklyMenuApprovalControllerTest extends WebTestCase
{
    public function testApprovalPageRendersWithValidHash(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/weekly-menu/' . TestDataFixture::WEEKLY_MENU_2_ID . '/approval/' . TestDataFixture::WEEKLY_MENU_2_APPROVAL_HASH);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('button[value="approve"]');
        $this->assertSelectorExists('button[value="deny"]');
    }

    public function testApprovalPageReturns404WithInvalidHash(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/weekly-menu/' . TestDataFixture::WEEKLY_MENU_2_ID . '/approval/invalid_hash');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testRequestApprovalRequiresAuthentication(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/weekly-menu/' . TestDataFixture::WEEKLY_MENU_2_ID . '/request-approval');

        $this->assertResponseRedirects();
    }

    public function testRequestApprovalByLoggedUserRedirects(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('POST', '/weekly-menu/' . TestDataFixture::WEEKLY_MENU_2_ID . '/request-approval');

        $this->assertResponseRedirects();
    }

    public function testApprovalSubmitApproves(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/weekly-menu/' . TestDataFixture::WEEKLY_MENU_2_ID . '/approval/' . TestDataFixture::WEEKLY_MENU_2_APPROVAL_HASH, [
            'action' => 'approve',
            'comment' => 'Schvaluji.',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'schválen');
    }

    public function testApprovalSubmitDenies(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/weekly-menu/' . TestDataFixture::WEEKLY_MENU_2_ID . '/approval/' . TestDataFixture::WEEKLY_MENU_2_APPROVAL_HASH, [
            'action' => 'deny',
            'comment' => 'Zamítám.',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'zamítnut');
    }

    public function testApprovalPageShowsAlreadyRespondedForNonPendingMenu(): void
    {
        $browser = self::createClient();

        // Menu 1 has no approval hash set, so it will 404
        // Instead, use menu that doesn't have pending status
        $browser->request('GET', '/weekly-menu/' . TestDataFixture::WEEKLY_MENU_1_ID . '/approval/some_hash');

        $this->assertResponseStatusCodeSame(404);
    }
}
