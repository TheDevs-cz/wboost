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

        $browser->request('GET', '/projects');

        $this->assertResponseIsSuccessful();
        // Both projects (owned by user1 and user2) are listed for the admin...
        $this->assertSelectorTextContains('body', 'Project 1');
        $this->assertSelectorTextContains('body', 'Project 2');
        // ...with the owner label on the non-owned cards.
        $this->assertSelectorTextContains('body', 'vlastník:');
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
