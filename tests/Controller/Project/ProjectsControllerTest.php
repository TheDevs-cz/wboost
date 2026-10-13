<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Project;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

final class ProjectsControllerTest extends WebTestCase
{
    public function testAdminSeesEveryProjectWithOwnerLabel(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $browser->request('GET', '/projects');

        $this->assertResponseIsSuccessful();
        // Both projects (owned by user1 and user2) are listed for the admin...
        $this->assertSelectorTextContains('body', 'Project 1');
        $this->assertSelectorTextContains('body', 'Project 2');
        // One list, no tier headings. The admin owns nothing but PROJECT_2 is
        // shared with them, so it ranks ahead of the un-shared PROJECT_1 — both as
        // outlined "foreign" cards with an owner line.
        $this->assertSelectorNotExists('h4.header-title');
        $this->assertSelectorCount(2, '.project-card--foreign');
        $owners = $crawler->filter('.project-card-owner');
        $this->assertStringContainsString('Sdíleno od', $owners->eq(0)->text());
        $this->assertStringNotContainsString('Sdíleno od', $owners->eq(1)->text());
    }

    public function testProjectCardShowsMonogramWithBrandColor(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $browser->request('GET', '/projects');

        $this->assertResponseIsSuccessful();
        // Project 1 has no logo images, so its card falls back to the initials
        // monogram colored by its manual's primary brand color.
        $this->assertSelectorTextContains('span[style*="background: #C8102E"]', 'P1');
    }

    public function testNonAdminIsScopedToTheirProjects(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_2_EMAIL);

        $browser->request('GET', '/projects');

        // user2 has exactly one accessible project (PROJECT_2): a plain user with a
        // single project is redirected straight to it — proving they do NOT get the
        // admin all-projects list.
        $this->assertResponseStatusCodeSame(302);
    }
}
