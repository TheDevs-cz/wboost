<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Project;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * The /projects dialog variant of add_project / edit_project (`?modal=1`,
 * project_form_modal_controller): a bare fragment on GET, the fragment again
 * with a 422 on validation errors, a JSON redirect back to /projects (or the
 * project overview with `back=dashboard`) on success.
 */
final class ProjectFormModalTest extends WebTestCase
{
    public function testProjectsPageWiresTheSharedDialog(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('GET', '/projects');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#project-form-modal[data-controller="project-form-modal"]');
        self::assertSelectorExists('a[href="/add-project"][data-project-form-modal]');
        self::assertSelectorExists(sprintf('a[href="/edit-project/%s"][data-project-form-modal]', TestDataFixture::PROJECT_1_ID));
    }

    public function testEditFragmentShowsErrorsInsideAndSavesAsJson(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('GET', '/edit-project/' . TestDataFixture::PROJECT_1_ID . '?modal=1');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('<html', (string) $client->getResponse()->getContent());
        self::assertSelectorTextContains('.modal-title', 'Upravit projekt');
        self::assertSelectorNotExists('#project-modules');
        $form = $crawler->filter('.modal-body form');
        self::assertSame(sprintf('/edit-project/%s?modal=1', TestDataFixture::PROJECT_1_ID), $form->attr('action'));
        self::assertSelectorExists('.modal-body button[data-bs-dismiss="modal"]');

        $client->submit($form->form(), ['project_form[name]' => 'x']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('<html', (string) $client->getResponse()->getContent());
        self::assertSelectorExists('.modal-body .is-invalid');

        $form = $client->getCrawler()->filter('.modal-body form')->form();
        $client->submit($form, ['project_form[name]' => 'Přejmenovaný projekt']);

        self::assertResponseIsSuccessful();
        self::assertSame(['redirect' => '/projects'], json_decode((string) $client->getResponse()->getContent(), true));

        $project = self::getContainer()->get(ProjectRepository::class)->get(Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        self::assertSame('Přejmenovaný projekt', $project->name);
    }

    public function testEditFromTheOverviewReturnsThere(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('GET', '/edit-project/' . TestDataFixture::PROJECT_1_ID . '?modal=1&back=dashboard');

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('.modal-body form');
        self::assertSame(sprintf('/edit-project/%s?modal=1&back=dashboard', TestDataFixture::PROJECT_1_ID), $form->attr('action'));

        $client->submit($form->form(), ['project_form[name]' => 'Project 1 z přehledu']);

        self::assertResponseIsSuccessful();
        self::assertSame(['redirect' => '/project/' . TestDataFixture::PROJECT_1_ID], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testAddFragmentCreatesProjectAndReturnsToProjects(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('GET', '/add-project?modal=1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.modal-title', 'Nový projekt');
        $form = $crawler->filter('.modal-body form');
        self::assertSame('/add-project?modal=1', $form->attr('action'));

        $client->submit($form->form(), ['project_form[name]' => 'Projekt z dialogu']);

        self::assertResponseIsSuccessful();
        self::assertSame(['redirect' => '/projects'], json_decode((string) $client->getResponse()->getContent(), true));

        $client->request('GET', '/projects');
        self::assertSelectorTextContains('body', 'Projekt vytvořen!');
        self::assertSelectorTextContains('body', 'Projekt z dialogu');
    }

    public function testFullPagesStayUnchanged(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('GET', '/edit-project/' . TestDataFixture::PROJECT_1_ID);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.pf-card form');
        self::assertNull($crawler->filter('.pf-card form')->attr('action'));

        $client->submit($crawler->filter('.pf-card form')->form(), ['project_form[name]' => 'Project 1 nový']);
        self::assertResponseRedirects('/project/' . TestDataFixture::PROJECT_1_ID);
    }
}
