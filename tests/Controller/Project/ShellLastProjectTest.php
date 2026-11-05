<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Project;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Twig\ShellExtension;

/**
 * F15 (2026-11-05): pages without a project of their own (admin, Propojené
 * aplikace, /projects…) keep the last opened project in the sidebar — the
 * quick-nav trigger shows it and the PROJEKT section stays, with nothing in it
 * highlighted. Remembered in the session; the voter is re-checked on every
 * render, so a deleted or no longer shared project disappears.
 */
final class ShellLastProjectTest extends WebTestCase
{
    private const string PROJECT_NAV = '.sb nav[aria-label="Moduly projektu"]';

    public function testAdminPageKeepsTheLastOpenedProject(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID);
        self::assertResponseIsSuccessful();

        $crawler = $client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('.sb .qn-trigger .nm', 'Project 1');
        self::assertSame('true', $crawler->filter('#qn-' . TestDataFixture::PROJECT_1_ID)->attr('aria-selected'));

        $nav = $crawler->filter(self::PROJECT_NAV);
        self::assertCount(1, $nav);
        self::assertCount(1, $nav->filter(sprintf('a[href="/project/%s"]', TestDataFixture::PROJECT_1_ID)));
        self::assertCount(1, $nav->filter('a[title="Facebook a Instagram"]'));
        // Nothing in the remembered project is "current" — the admin item is.
        self::assertCount(0, $nav->filter('[aria-current="page"]'));
        self::assertSame('page', $crawler->filter('.sb a.nav-i[href="/admin/users"]')->attr('aria-current'));
        // The page header still reads as an admin page.
        self::assertSelectorTextContains('header.head .lbl', 'Administrace');
    }

    public function testNothingRememberedShowsTheProjectPicker(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sb .qn-trigger', 'Vybrat projekt…');
        self::assertSelectorNotExists(self::PROJECT_NAV);
    }

    public function testProjectsListKeepsTheRememberedProject(): void
    {
        $client = self::createClient();
        // The admin sees several projects (a one-project user is redirected
        // straight into it).
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('GET', '/project/' . TestDataFixture::PROJECT_2_ID);
        $crawler = $client->request('GET', '/projects');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sb .qn-trigger .nm', 'Project 2');
        self::assertSame('page', $crawler->filter('.sb a.nav-i[href="/projects"]')->attr('aria-current'));
        self::assertCount(0, $crawler->filter(self::PROJECT_NAV . ' [aria-current="page"]'));
    }

    public function testSharedUserLosesTheRememberedProjectWhenUnshared(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::SHARED_USER_EMAIL);

        $client->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID);
        self::assertResponseIsSuccessful();

        $crawler = $client->request('GET', '/user-profile/connected-apps');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sb .qn-trigger .nm', 'Project 1');
        // Shared read-only: no Galerie (project_edit), the rest is there.
        self::assertCount(0, $crawler->filter(self::PROJECT_NAV . ' a[title="Galerie"]'));
        self::assertCount(1, $crawler->filter(self::PROJECT_NAV . ' a[title="Fonty"]'));

        $container = $client->getContainer();
        $project = $container->get(ProjectRepository::class)->get(Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        $project->unshare($container->get(UserRepository::class)->get(TestDataFixture::SHARED_USER_EMAIL));
        $container->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/user-profile/connected-apps');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.sb .qn-trigger .nm');
        self::assertSelectorNotExists(self::PROJECT_NAV);
        self::assertFalse($this->session($client)->has(ShellExtension::LAST_PROJECT_SESSION_KEY));
    }

    public function testDeletedProjectIsForgotten(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);

        $client->request('GET', '/project/' . TestDataFixture::PROJECT_2_ID);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/delete-project/' . TestDataFixture::PROJECT_2_ID);
        self::assertResponseRedirects('/dashboard');

        $client->request('GET', '/user-profile/connected-apps');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::PROJECT_NAV);
        self::assertSelectorNotExists('.sb .qn-trigger .nm');
        self::assertFalse($this->session($client)->has(ShellExtension::LAST_PROJECT_SESSION_KEY));
    }

    public function testLogoutForgetsTheRememberedProject(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID);
        self::assertTrue($this->session($client)->has(ShellExtension::LAST_PROJECT_SESSION_KEY));

        $client->request('GET', '/logout');

        self::assertFalse($this->session($client)->has(ShellExtension::LAST_PROJECT_SESSION_KEY));
    }

    private function session(KernelBrowser $client): SessionInterface
    {
        return $client->getRequest()->getSession();
    }

    /**
     * Sanity: a project page itself still marks its own items as current.
     */
    public function testProjectPageStillHighlightsItsOwnItem(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $crawler = $client->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID);

        self::assertResponseIsSuccessful();
        $current = $crawler->filter(self::PROJECT_NAV . ' [aria-current="page"]')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
        self::assertSame(['Přehled'], $current);
    }
}
