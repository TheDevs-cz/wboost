<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * F12 follow-up: the sort endpoint's voter checks only the project in the URL,
 * so the handler must leave templates of any OTHER project alone.
 */
final class SortTemplatesControllerTest extends WebTestCase
{
    public function testOnlyTheUrlProjectsTemplatesAreSorted(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        // Somebody else's project (user 2), user 1 has no access to it.
        $foreignBefore = $this->position(TestDataFixture::CUSTOM_TEMPLATE_2_ID);
        $foreignSocialBefore = $this->position(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_2_ID);

        $client->request(
            'POST',
            '/sort-templates/' . TestDataFixture::PROJECT_1_ID,
            content: (string) json_encode(['sorted' => [
                'not-a-uuid',
                TestDataFixture::CUSTOM_TEMPLATE_2_ID,
                '00000000-0000-0000-0000-00000000dead',
                TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID,
                TestDataFixture::SOCIAL_NETWORK_TEMPLATE_2_ID,
                TestDataFixture::CUSTOM_TEMPLATE_1_ID,
            ]]),
        );

        self::assertResponseIsSuccessful();
        self::assertSame($foreignBefore, $this->position(TestDataFixture::CUSTOM_TEMPLATE_2_ID));
        self::assertSame($foreignSocialBefore, $this->position(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_2_ID));
        self::assertSame(3, $this->position(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID));
        self::assertSame(5, $this->position(TestDataFixture::CUSTOM_TEMPLATE_1_ID));
    }

    private function position(string $templateId): int
    {
        $position = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT position FROM template WHERE id = ?', [$templateId]);
        self::assertIsInt($position);

        return $position;
    }
}
