<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * F12: the sort endpoint's voter checks only the project in the URL, so the
 * handler must leave categories of any OTHER project alone.
 */
final class SortTemplateCategoriesControllerTest extends WebTestCase
{
    private const string OWN_SECOND_ID = '00000000-0000-0000-0000-0000000004f3';
    private const string FOREIGN_ID = '00000000-0000-0000-0000-0000000004f4';

    public function testOnlyTheUrlProjectsCategoriesAreSorted(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->insert('template_category', [
            'id' => self::OWN_SECOND_ID,
            'created_at' => '2026-11-05 10:00:00',
            'name' => 'Druhá',
            'position' => 1,
            'project_id' => TestDataFixture::PROJECT_1_ID,
        ]);
        // Somebody else's project (user 2), user 1 has no access to it.
        $connection->insert('template_category', [
            'id' => self::FOREIGN_ID,
            'created_at' => '2026-11-05 10:00:00',
            'name' => 'Cizí',
            'position' => 7,
            'project_id' => TestDataFixture::PROJECT_2_ID,
        ]);

        $client->request(
            'POST',
            '/sort-template-categories/' . TestDataFixture::PROJECT_1_ID,
            content: (string) json_encode(['sorted' => [self::FOREIGN_ID, self::OWN_SECOND_ID, 'not-a-uuid', '00000000-0000-0000-0000-00000000dead', TestDataFixture::TEMPLATE_CATEGORY_1_ID]]),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(7, $this->position(self::FOREIGN_ID));
        self::assertSame(1, $this->position(self::OWN_SECOND_ID));
        self::assertSame(4, $this->position(TestDataFixture::TEMPLATE_CATEGORY_1_ID));
    }

    private function position(string $categoryId): int
    {
        $position = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT position FROM template_category WHERE id = ?', [$categoryId]);
        self::assertIsInt($position);

        return $position;
    }
}
