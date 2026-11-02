<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * The Šablony listing: "Nejpoužívanější" row from the export log, the
 * "Nová šablona" type picker and the card contracts the other tests rely on.
 */
final class TemplatesListingTest extends WebTestCase
{
    public function testMostUsedRowRanksTemplatesByExportsInTheLast30Days(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $this->recordExports(TestDataFixture::GROUPED_TEMPLATE_ID, 3, '-1 day');
        $this->recordExports(TestDataFixture::CUSTOM_TEMPLATE_1_ID, 1, '-2 days');
        // Too old to count.
        $this->recordExports(TestDataFixture::CUSTOM_TEMPLATE_1_ID, 9, '-40 days');

        $crawler = $client->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID . '/templates');
        self::assertResponseIsSuccessful();

        $top = $crawler->filter('.tp-top .tp-card');
        self::assertCount(2, $top);
        self::assertStringContainsString('3× exportováno za 30 dní', $top->eq(0)->text());
        self::assertStringContainsString('1× exportováno za 30 dní', $top->eq(1)->text());

        // Highlighted cards carry no sort id, menu or modals — the regular
        // card stays the only one.
        self::assertCount(0, $crawler->filter('.tp-top [data-entity-id], .tp-top .dropdown, .tp-top .modal'));
        self::assertCount(1, $crawler->filter('[data-entity-id="' . TestDataFixture::GROUPED_TEMPLATE_ID . '"]'));
        self::assertCount(1, $crawler->filter('a[href="/template/' . TestDataFixture::GROUPED_TEMPLATE_ID . '/copy"]'));
    }

    public function testNoMostUsedRowWithoutRecentExports(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID . '/templates');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.tp-top'));
    }

    public function testNewTemplateDialogLinksEveryCreateRoute(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID . '/templates');
        self::assertResponseIsSuccessful();

        $dialog = $crawler->filter('#templateNewModal');
        self::assertCount(1, $dialog);
        $prefix = '/project/' . TestDataFixture::PROJECT_1_ID;
        foreach (['/add-template', '/add-template-group', '/template-groups/from-template', '/add-template-document'] as $route) {
            self::assertCount(1, $dialog->filter('a[href="' . $prefix . $route . '"]'), $route);
        }
        // "Šablona z obrázku" is a teaser — no AI import exists yet.
        self::assertCount(1, $dialog->filter('.tp-type.prem button[disabled]'));
    }

    private function recordExports(string $templateId, int $count, string $when): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        for ($i = 0; $i < $count; $i++) {
            $connection->insert('export_event', [
                'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
                'exported_at' => (new \DateTimeImmutable($when))->format('Y-m-d H:i:s'),
                'template_type' => 'template',
                'channel' => 'web',
                'template_id' => $templateId,
                'template_name' => 'Test',
                'variant_id' => $templateId,
                'project_id' => TestDataFixture::PROJECT_1_ID,
                'project_name' => 'Project',
                'owner_id' => $templateId,
                'owner_email' => 'owner@example.com',
                'triggered_by_user_id' => null,
                'format' => 'png',
            ]);
        }
    }
}
