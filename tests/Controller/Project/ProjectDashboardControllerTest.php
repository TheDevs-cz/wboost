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
        self::assertSelectorExists(sprintf('a[href="/project/%s/social-networks"]', $projectId));
        self::assertSelectorExists(sprintf('a[href="/project/%s/custom-templates"]', $projectId));
        self::assertSelectorExists(sprintf('a[href="/project/%s/gallery"]', $projectId));
        self::assertSelectorExists(sprintf('a[href="/project/%s/calendars"]', $projectId));
        self::assertSelectorExists(sprintf('a[href="/project/%s/fonts"]', $projectId));
        self::assertSelectorExists(sprintf('a[href="/project/%s/emails"]', $projectId));
        self::assertSelectorExists(sprintf('a[href="/project/%s/weekly-menus"]', $projectId));

        // Owner can edit, so the header offers the edit action.
        self::assertSelectorExists(sprintf('a[href="/edit-project/%s"]', $projectId));
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
    }

    public function testForbiddenForUnrelatedUser(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('GET', self::URL);

        self::assertResponseStatusCodeSame(403);
    }
}
