<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Project;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * The project dashboard lists a tile per module (including the edit-gated
 * gallery) under the brand header: "Založeno" line + ⋯ menu (Upravit opens the
 * shared edit dialog, Sdílet projekt is admin-only). Since F3 (2026-11-05) it
 * has no users/templates/owner meta and no recent-templates strip.
 */
final class ProjectDashboardControllerTest extends WebTestCase
{
    private const string URL = '/project/' . TestDataFixture::PROJECT_1_ID;

    public function testRedirectsGuestToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', self::URL);

        self::assertResponseRedirects();
    }

    public function testRendersAllModuleTilesForProjectOwner(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();

        $projectId = TestDataFixture::PROJECT_1_ID;
        self::assertSelectorExists(sprintf('a[href="/project/%s/manuals"]', $projectId));
        self::assertSelectorExists(sprintf('a[href="/project/%s/templates"]', $projectId));
        self::assertSelectorExists(sprintf('a[href="/project/%s/gallery"]', $projectId));
        // Kalendáře is a module like the others: project 1 has it switched on,
        // so the left nav links its list (a project without it does not —
        // ProjectModulesTest::testDashboardAndNavShowOnlyEnabledModules).
        self::assertSelectorExists(sprintf('.sb a[href="/project/%s/calendars"]', $projectId));
        // … and the overview has its tile with the number of calendars.
        self::assertSelectorExists(sprintf('.pd-tiles a.pd-tile[data-pdk="kalendare"][href="/project/%s/calendars"]', $projectId));
        self::assertSelectorTextContains('.pd-tile[data-pdk="kalendare"] .pd-n', '2');
        self::assertSelectorExists(sprintf('a[href="/project/%s/fonts"]', $projectId));
        self::assertSelectorExists(sprintf('a[href="/project/%s/emails"]', $projectId));
        self::assertSelectorExists(sprintf('a[href="/project/%s/weekly-menus"]', $projectId));

        // The social module died with the template merge — no tile, no nav item.
        self::assertSelectorNotExists(sprintf('a[href="/project/%s/social-networks"]', $projectId));

        // No recent-templates strip any more.
        self::assertSelectorNotExists('#pdRecentH');
        self::assertSelectorNotExists(sprintf('a[href="/template/%s/variants"]', TestDataFixture::CUSTOM_TEMPLATE_1_ID));

        // Owner can edit: the header ⋯ menu offers Upravit in the shared dialog
        // (returning here), but sharing stays admin-only.
        self::assertSelectorExists(sprintf('.dhead a[href="/edit-project/%s"][data-project-form-modal="dashboard"]', $projectId));
        self::assertSelectorExists('#project-form-modal[data-controller="project-form-modal"]');
        self::assertSelectorNotExists(sprintf('.dhead [data-bs-target="#share-modal-%s"]', $projectId));

        // The meta line only carries the creation date.
        self::assertSelectorTextContains('.dhead .meta', 'Založeno');
        self::assertSelectorTextNotContains('.dhead .meta', 'uživatel');
        self::assertSelectorTextNotContains('.dhead .meta', 'šablon');
    }

    public function testReadOnlyUserSeesNoEditGatedTiles(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::INVITED_USER_EMAIL);

        $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();

        $projectId = TestDataFixture::PROJECT_1_ID;
        self::assertSelectorExists(sprintf('a[href="/project/%s/manuals"]', $projectId));
        self::assertSelectorNotExists(sprintf('a[href="/project/%s/gallery"]', $projectId));
        self::assertSelectorNotExists(sprintf('a[href="/edit-project/%s"]', $projectId));
        // Nothing to offer, so no ⋯ menu and no edit dialog.
        self::assertSelectorNotExists('.dhead .card-menu-btn');
        self::assertSelectorNotExists('#project-form-modal');

        // F3: the owner / "Sdíleno od" line is gone from the header.
        self::assertSelectorNotExists('[title^="Vlastník:"]');
    }

    public function testAdminMenuOffersEditAndSharing(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        // PROJECT_2 (owned by user2) is not shared with the admin — god-mode access.
        $projectId = TestDataFixture::PROJECT_2_ID;
        $client->request('GET', '/project/' . $projectId);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('.dhead a[href="/edit-project/%s"][data-project-form-modal="dashboard"]', $projectId));
        self::assertSelectorTextContains(sprintf('.dhead [data-bs-target="#share-modal-%s"]', $projectId), 'Sdílet projekt');
        self::assertSelectorExists(sprintf('#share-modal-%s', $projectId));
        self::assertSelectorNotExists('[title^="Vlastník:"]');
    }

    public function testForbiddenForUnrelatedUser(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('GET', self::URL);

        self::assertResponseStatusCodeSame(403);
    }
}
