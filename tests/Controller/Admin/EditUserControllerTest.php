<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use WBoost\Web\Entity\User;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

final class EditUserControllerTest extends WebTestCase
{
    public function testForbiddenForNonAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', '/admin/users/' . TestDataFixture::USER_2_ID . '/edit');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testEditUpdatesUser(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $browser->request('GET', '/admin/users/' . TestDataFixture::INVITED_USER_ID . '/edit');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Uložit')->form();
        $form['edit_user_form[name]'] = 'Admin Edited';
        $form['edit_user_form[role]'] = User::ROLE_ADMIN;
        $browser->submit($form);

        $this->assertResponseRedirects('/admin/users');

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $user = self::getContainer()->get(UserRepository::class)->getById(Uuid::fromString(TestDataFixture::INVITED_USER_ID));
        self::assertSame('Admin Edited', $user->name);
        self::assertContains(User::ROLE_ADMIN, $user->getRoles());
    }

    /**
     * The OAuth scope ceiling: the form starts at the read + export default and
     * saves exactly what the admin ticks — including unticking everything.
     */
    public function testEditChangesTheMcpScopeCeiling(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $browser->request('GET', '/admin/users/' . TestDataFixture::USER_2_ID . '/edit');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Uložit')->form();
        $fields = $form['edit_user_form[mcpScopes]'];
        self::assertIsArray($fields);
        $checkboxes = array_values(array_filter(
            $fields,
            static fn (mixed $field): bool => $field instanceof ChoiceFormField,
        ));
        self::assertCount(4, $checkboxes);

        $ticked = [];
        foreach ($checkboxes as $checkbox) {
            if ($checkbox->hasValue()) {
                $ticked[] = $checkbox->getValue();
            }
        }
        self::assertSame(User::DEFAULT_MCP_SCOPES, $ticked);

        foreach ($checkboxes as $checkbox) {
            $checkbox->untick();
        }
        $checkboxes[2]->tick();
        $checkboxes[3]->tick();
        $browser->submit($form);

        $this->assertResponseRedirects('/admin/users');

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $user = self::getContainer()->get(UserRepository::class)->getById(Uuid::fromString(TestDataFixture::USER_2_ID));
        self::assertSame(['templates:design', 'gallery:write'], $user->mcpScopes);
    }
}
