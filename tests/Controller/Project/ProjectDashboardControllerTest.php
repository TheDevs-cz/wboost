<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Project;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * The project dashboard lists a tile per module (including the edit-gated
 * gallery) plus the brand header and recent templates strip.
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
        // Kalendáře isn't built yet — reachable from "Objevte další moduly" only.
        self::assertSelectorNotExists(sprintf('a[href="/project/%s/calendars"]', $projectId));
        self::assertSelectorExists(sprintf('a[href="/project/%s/fonts"]', $projectId));
        self::assertSelectorExists(sprintf('a[href="/project/%s/emails"]', $projectId));
        self::assertSelectorExists(sprintf('a[href="/project/%s/weekly-menus"]', $projectId));

        // The social module died with the template merge — no tile, no nav item.
        self::assertSelectorNotExists(sprintf('a[href="/project/%s/social-networks"]', $projectId));

        // Recent templates: a grouped template opens its group fill page, a
        // plain one its variants page.
        self::assertSelectorExists(sprintf('a[href="/template-group/%s/fill"]', TestDataFixture::TEMPLATE_GROUP_1_ID));
        self::assertSelectorExists(sprintf('a[href="/template/%s/variants"]', TestDataFixture::CUSTOM_TEMPLATE_1_ID));

        // Owner can edit, so the header offers the edit action.
        self::assertSelectorExists(sprintf('a[href="/edit-project/%s"]', $projectId));

        // Your own project carries no owner line.
        self::assertSelectorNotExists('[title^="Vlastník:"]');
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

        // Shared with this user, so the header names who shared it.
        self::assertSelectorTextContains('[title^="Vlastník:"]', 'Sdíleno od');
    }

    public function testAdminSeesOwnerOfProjectNotSharedWithThem(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        // PROJECT_2 (owned by user2) is not shared with the admin — god-mode access.
        $client->request('GET', '/project/' . TestDataFixture::PROJECT_2_ID);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[title^="Vlastník:"]');
        self::assertSelectorTextNotContains('[title^="Vlastník:"]', 'Sdíleno od');
    }

    public function testForbiddenForUnrelatedUser(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('GET', self::URL);

        self::assertResponseStatusCodeSame(403);
    }
}
