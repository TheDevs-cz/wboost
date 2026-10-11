<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Project;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Repository\ProjectModuleChangeRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\ProjectModule;

/**
 * Project modules: only switched-on modules show in the nav and on the
 * dashboard, the rest sit behind "Objevte další moduly". A project editor
 * switches one on (admins get an e-mail); only an admin switches one off.
 *
 * Fixtures: Project 1 has every module, Project 2 (owned by user 2) only the
 * new-project defaults (Manuály + Šablony).
 */
final class ProjectModulesTest extends WebTestCase
{
    private const string PROJECT_2 = TestDataFixture::PROJECT_2_ID;

    public function testDashboardAndNavShowOnlyEnabledModules(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('GET', '/project/' . self::PROJECT_2);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('a[href="/project/%s/manuals"]', self::PROJECT_2));
        self::assertSelectorExists(sprintf('a[href="/project/%s/templates"]', self::PROJECT_2));
        self::assertSelectorExists(sprintf('a[href="/project/%s/fonts"]', self::PROJECT_2));
        self::assertSelectorNotExists(sprintf('a[href="/project/%s/emails"]', self::PROJECT_2));
        self::assertSelectorNotExists(sprintf('a[href="/project/%s/weekly-menus"]', self::PROJECT_2));
        self::assertSelectorNotExists(sprintf('a[href="/project/%s/calendars"]', self::PROJECT_2));
        // Nav item + dashboard tile.
        self::assertCount(2, $client->getCrawler()->filter(sprintf('a[href="/project/%s/modules"]', self::PROJECT_2)));
    }

    public function testProjectUsingEveryModuleHasNothingToDiscover(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(sprintf('a[href="/project/%s/modules"]', TestDataFixture::PROJECT_1_ID));
    }

    public function testDisabledModuleLandingPagesRedirectToDiscover(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        foreach (['emails', 'weekly-menus', 'meals', 'diets', 'dish-types'] as $path) {
            $client->request('GET', sprintf('/project/%s/%s', self::PROJECT_2, $path));
            self::assertResponseRedirects(sprintf('/project/%s/modules', self::PROJECT_2), message: $path);
        }

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertAnySelectorTextContains('.alert-info', 'Modul Jídelníčky není v tomto projektu zapnutý.');
    }

    public function testDiscoverPageListsDisabledModules(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('GET', sprintf('/project/%s/modules', self::PROJECT_2));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-module="email_signatures"] button[type="submit"]');
        self::assertSelectorExists('[data-module="weekly_menus"] button[type="submit"]');
        self::assertSelectorNotExists('[data-module="manuals"]');
        self::assertSelectorNotExists('[data-module="templates"]');
    }

    public function testOwnerEnablesModuleAndAdminsAreEmailed(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $this->submitDiscoverForm($client, ProjectModule::EmailSignatures);

        self::assertResponseRedirects(sprintf('/project/%s/emails', self::PROJECT_2));

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', 'j.mikes@me.com');
        self::assertEmailHtmlBodyContains($email, 'E-maily');
        self::assertEmailHtmlBodyContains($email, 'Project 2');
        self::assertEmailHtmlBodyContains($email, sprintf('/edit-project/%s#project-modules', self::PROJECT_2));

        $project = self::getContainer()->get(ProjectRepository::class)->get(Uuid::fromString(self::PROJECT_2));
        self::assertTrue($project->hasModule(ProjectModule::EmailSignatures));

        $changes = self::getContainer()->get(ProjectModuleChangeRepository::class)->latestForProject($project->id);
        self::assertCount(1, $changes);
        self::assertSame(ProjectModule::EmailSignatures, $changes[0]->module);
        self::assertTrue($changes[0]->enabled);
        self::assertSame(TestDataFixture::USER_2_EMAIL, $changes[0]->changedBy?->email);

        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testAdminEnablingDoesNotEmailAdmins(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('GET', '/edit-project/' . self::PROJECT_2);
        self::assertResponseIsSuccessful();

        $form = $client->getCrawler()
            ->filter('#project-modules [data-module="weekly_menus"] form')
            ->form();
        $client->submit($form);

        self::assertResponseRedirects(sprintf('/edit-project/%s#project-modules', self::PROJECT_2));
        self::assertEmailCount(0);

        $project = self::getContainer()->get(ProjectRepository::class)->get(Uuid::fromString(self::PROJECT_2));
        self::assertTrue($project->hasModule(ProjectModule::WeeklyMenus));
    }

    public function testAdminDisablesModule(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('GET', '/edit-project/' . TestDataFixture::PROJECT_1_ID);
        self::assertResponseIsSuccessful();

        $form = $client->getCrawler()
            ->filter('#project-modules [data-module="weekly_menus"] form')
            ->form();
        $client->submit($form);

        self::assertResponseRedirects(sprintf('/edit-project/%s#project-modules', TestDataFixture::PROJECT_1_ID));

        $project = self::getContainer()->get(ProjectRepository::class)->get(Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        self::assertFalse($project->hasModule(ProjectModule::WeeklyMenus));

        $client->followRedirect();
        self::assertSelectorTextContains('#project-modules', 'vypnul(a) modul Jídelníčky');
    }

    public function testOwnerCannotDisableModuleOrSeeAdminCard(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/edit-project/' . TestDataFixture::PROJECT_1_ID);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#project-modules');

        $client->request('POST', sprintf('/project/%s/modules/weekly_menus/disable', TestDataFixture::PROJECT_1_ID));
        self::assertResponseStatusCodeSame(403);
    }

    public function testReadOnlyMemberCannotEnable(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::INVITED_USER_EMAIL);

        $client->request('POST', sprintf('/project/%s/modules/email_signatures/enable', TestDataFixture::PROJECT_1_ID));
        self::assertResponseStatusCodeSame(403);
    }

    public function testEnableRequiresCsrfToken(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('POST', sprintf('/project/%s/modules/email_signatures/enable', self::PROJECT_2), ['_token' => 'nope']);
        self::assertResponseStatusCodeSame(403);

        $project = self::getContainer()->get(ProjectRepository::class)->get(Uuid::fromString(self::PROJECT_2));
        self::assertFalse($project->hasModule(ProjectModule::EmailSignatures));
    }

    private function submitDiscoverForm(KernelBrowser $client, ProjectModule $module): void
    {
        $client->request('GET', sprintf('/project/%s/modules', self::PROJECT_2));
        self::assertResponseIsSuccessful();

        $form = $client->getCrawler()
            ->filter(sprintf('[data-module="%s"] form', $module->value))
            ->form();
        $client->submit($form);
    }
}
