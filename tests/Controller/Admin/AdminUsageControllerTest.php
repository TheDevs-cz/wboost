<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Admin;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\ExportEvent;
use WBoost\Web\Entity\UserActivityDay;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportedTemplateType;

final class AdminUsageControllerTest extends WebTestCase
{
    public function testAnonymousRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/usage');

        $this->assertResponseRedirects('/login');
    }

    public function testForbiddenForNonAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', '/admin/usage');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testRendersEmptyStateForAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $browser->request('GET', '/admin/usage');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Zatím nebyly zaznamenány žádné exporty');
    }

    public function testRendersSeededUsageForAdmin(): void
    {
        $browser = self::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new ExportEvent(
            Uuid::uuid7(),
            new DateTimeImmutable('2026-06-15 10:00:00'),
            ExportedTemplateType::SocialNetwork,
            ExportChannel::Web,
            Uuid::uuid4(),
            'Letní kampaň',
            Uuid::uuid4(),
            Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            'Projekt Alfa',
            Uuid::fromString(TestDataFixture::USER_1_ID),
            TestDataFixture::USER_1_EMAIL,
            null,
        ));
        $entityManager->flush();

        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);
        $browser->request('GET', '/admin/usage');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', TestDataFixture::USER_1_EMAIL);
        $this->assertSelectorTextContains('body', 'Projekt Alfa');
        $this->assertSelectorExists('#usage-chart');
    }

    public function testClientHeatmapShowsNameBadgeAndBothMetrics(): void
    {
        $browser = self::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement(
            'UPDATE "user" SET name = ? WHERE id = ?',
            ['Lukáš Rejda', TestDataFixture::USER_1_ID],
        );

        // One template exported three times this month in one project.
        $templateId = Uuid::uuid4();
        $variantId = Uuid::uuid4();
        foreach (['01 10:00:00', '01 11:00:00', '01 12:00:00'] as $time) {
            $entityManager->persist(new ExportEvent(
                Uuid::uuid7(),
                new DateTimeImmutable(date('Y-m-') . $time),
                ExportedTemplateType::SocialNetwork,
                ExportChannel::Web,
                $templateId,
                'Letní kampaň',
                $variantId,
                Uuid::fromString(TestDataFixture::PROJECT_1_ID),
                'Projekt Alfa',
                Uuid::fromString(TestDataFixture::USER_1_ID),
                TestDataFixture::USER_1_EMAIL,
                null,
            ));
        }
        $entityManager->flush();

        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);
        $crawler = $browser->request('GET', '/admin/usage');

        $this->assertResponseIsSuccessful();

        // Client row: display name + round project-count badge, e-mail as the secondary line.
        $clientRow = $crawler->filter('.hm-clients tr.hm-cl')->first();
        self::assertSame('Lukáš Rejda', trim($clientRow->filter('.hm-nl b')->text()));
        self::assertSame('1', trim($clientRow->filter('.hm-nl .count')->text()));
        self::assertSame(TestDataFixture::USER_1_EMAIL, trim($clientRow->filter('.hm-em')->text()));

        // Projects are collapsed by default behind a disclosure button.
        $toggle = $clientRow->filter('button.hm-tg');
        self::assertSame('false', $toggle->attr('aria-expanded'));
        $projectsId = (string) $toggle->attr('aria-controls');
        $projects = $crawler->filter('#' . $projectsId);
        self::assertCount(1, $projects);
        self::assertNotNull($projects->attr('hidden'));
        self::assertStringContainsString('Projekt Alfa', $projects->text());

        // The cell carries both metrics and both levels; downloads is the default metric.
        self::assertSame('downloads', $crawler->filter('.hm-card[data-controller="usage-heatmap"]')->attr('data-metric'));
        $cell = $clientRow->filter('td.hm-c.now');
        self::assertCount(1, $cell);
        self::assertSame('3', trim($cell->filter('[data-only="downloads"]')->text()));
        self::assertSame('1', trim($cell->filter('[data-only="templates"]')->text()));
        self::assertSame('4', $cell->attr('data-ld'));
        self::assertSame('4', $cell->attr('data-lt'));
        self::assertSame('3 stažení · 1 šablona', $cell->attr('title'));

        $this->assertSelectorExists('#hm-search');
        $this->assertSelectorExists('.hm-metric button[data-metric="templates"]');
    }

    public function testRendersUserActivityForAdmin(): void
    {
        $browser = self::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new UserActivityDay(
            Uuid::uuid7(),
            Uuid::fromString(TestDataFixture::USER_1_ID),
            new DateTimeImmutable('today'),
            5,
        ));
        $entityManager->flush();

        // Mirror what the listener would have written onto the user.
        $connection = $entityManager->getConnection();
        $connection->executeStatement(
            'UPDATE "user" SET last_activity_at = ? WHERE id = ?',
            [(new DateTimeImmutable('today 09:15:00'))->format('Y-m-d H:i:s'), TestDataFixture::USER_1_ID],
        );

        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);
        $browser->request('GET', '/admin/usage');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Aktivita uživatelů');
        $this->assertSelectorExists('#activity-chart');
    }

    public function testRendersStorageEmptyStateBeforeAnyScan(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $browser->request('GET', '/admin/usage');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Inventura úložiště zatím neproběhla');
    }

    public function testRendersStorageUsagePerProjectAndOwner(): void
    {
        $browser = self::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement(
            'INSERT INTO storage_object (
                id, path, size, last_modified_at, category, referenced_by, reference_count,
                project_id, project_name, owner_id, owner_email, orphaned, scanned_at, scan_id
             ) VALUES (?, ?, ?, NULL, ?, ?, 1, ?, ?, ?, ?, false, ?, ?)',
            [
                Uuid::uuid7()->toString(),
                'fixtures/billed.png',
                5 * 1024 * 1024,
                'gallery_image',
                'file_upload.path',
                TestDataFixture::PROJECT_1_ID,
                'Projekt Alfa',
                TestDataFixture::USER_1_ID,
                TestDataFixture::USER_1_EMAIL,
                '2026-08-03 10:00:00',
                Uuid::uuid7()->toString(),
            ],
        );

        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);
        $browser->request('GET', '/admin/usage');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Úložiště');
        $this->assertSelectorTextContains('body', '5,0 MB');
        $this->assertSelectorExists('#storage-chart');
    }
}
