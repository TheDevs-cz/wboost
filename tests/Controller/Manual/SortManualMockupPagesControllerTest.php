<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Manual;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\MockupPageLayout;

/**
 * F12 follow-up: the sort endpoint's voter checks only the manual in the URL,
 * so the handler must leave mockup pages of any OTHER manual alone.
 */
final class SortManualMockupPagesControllerTest extends WebTestCase
{
    private const string OWN_FIRST_ID = '00000000-0000-0000-0000-0000000006a1';
    private const string OWN_SECOND_ID = '00000000-0000-0000-0000-0000000006a2';
    private const string FOREIGN_ID = '00000000-0000-0000-0000-0000000006a3';

    public function testOnlyTheUrlManualsPagesAreSorted(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $this->insert(self::OWN_FIRST_ID, TestDataFixture::MANUAL_1_ID, 0);
        $this->insert(self::OWN_SECOND_ID, TestDataFixture::MANUAL_1_ID, 1);
        // Somebody else's manual (project 2 of user 2), user 1 has no access to it.
        $this->insert(self::FOREIGN_ID, TestDataFixture::MANUAL_2_ID, 7);

        $client->request(
            'POST',
            '/sort-manual-mockup-pages/' . TestDataFixture::MANUAL_1_ID,
            content: (string) json_encode(['sorted' => [self::FOREIGN_ID, self::OWN_SECOND_ID, 'not-a-uuid', '00000000-0000-0000-0000-00000000dead', self::OWN_FIRST_ID]]),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(7, $this->position(self::FOREIGN_ID));
        self::assertSame(1, $this->position(self::OWN_SECOND_ID));
        self::assertSame(4, $this->position(self::OWN_FIRST_ID));
    }

    private function insert(string $id, string $manualId, int $position): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->insert('manual_mockup_page', [
            'id' => $id,
            'created_at' => '2026-11-05 10:00:00',
            'layout' => MockupPageLayout::cases()[0]->value,
            'name' => 'Vizitky',
            'manual_id' => $manualId,
            'images' => '[]',
            'position' => $position,
            'image_downloads' => '[]',
        ]);
    }

    private function position(string $id): int
    {
        $position = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT position FROM manual_mockup_page WHERE id = ?', [$id]);
        self::assertIsInt($position);

        return $position;
    }
}
