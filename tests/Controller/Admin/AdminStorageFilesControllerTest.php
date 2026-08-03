<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Admin;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\StorageCategory;

final class AdminStorageFilesControllerTest extends WebTestCase
{
    public function testAnonymousRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/storage/files');

        $this->assertResponseRedirects('/login');
    }

    public function testForbiddenForNonAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', '/admin/storage/files');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testListsInventoriedFilesForAdmin(): void
    {
        $browser = self::createClient();
        $this->seed($browser, 'fixtures/report-me.png', 4096, false);

        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);
        $browser->request('GET', '/admin/storage/files');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'report-me.png');
        $this->assertSelectorTextContains('body', 'file_upload.path');
    }

    public function testOrphanFilterNarrowsToUnreferencedFiles(): void
    {
        $browser = self::createClient();
        $this->seed($browser, 'fixtures/still-used.png', 100, false);
        $this->seed($browser, 'fixtures/left-behind.png', 200, true);

        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);
        $browser->request('GET', '/admin/storage/files?orphaned=yes');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'left-behind.png');
        $this->assertSelectorTextNotContains('body', 'still-used.png');
    }

    public function testProjectFilterNoneShowsOnlyUnattributedFiles(): void
    {
        $browser = self::createClient();
        $this->seed($browser, 'fixtures/has-project.png', 100, false);
        $this->seed($browser, 'fixtures/no-project.png', 200, true, attributed: false);

        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);
        $browser->request('GET', '/admin/storage/files?project=none');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'no-project.png');
        $this->assertSelectorTextNotContains('body', 'has-project.png');
    }

    public function testRescanRebuildsTheInventory(): void
    {
        $browser = self::createClient();

        // A row for a file that is not in the bucket — the scan must drop it.
        $this->seed($browser, 'fixtures/vanished.png', 100, true);

        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $browser->request('GET', '/admin/storage/files');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $browser->request('POST', '/admin/storage/rescan', ['_token' => $token, 'back' => 'files']);

        $this->assertResponseRedirects('/admin/storage/files');

        $count = $this->connection($browser)
            ->executeQuery('SELECT COUNT(*) FROM storage_object WHERE path = ?', ['fixtures/vanished.png'])
            ->fetchOne();

        self::assertEquals(0, $count);
    }

    public function testRescanIsRejectedWithoutCsrfToken(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::ADMIN_USER_EMAIL);

        $browser->request('POST', '/admin/storage/rescan');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testRescanForbiddenForNonAdmin(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('POST', '/admin/storage/rescan');

        $this->assertResponseStatusCodeSame(403);
    }

    private function seed(KernelBrowser $browser, string $path, int $size, bool $orphaned, bool $attributed = true): void
    {
        $this->connection($browser)->executeStatement(
            'INSERT INTO storage_object (
                id, path, size, last_modified_at, category, referenced_by, reference_count,
                project_id, project_name, owner_id, owner_email, orphaned, scanned_at, scan_id
             ) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                Uuid::uuid7()->toString(),
                $path,
                $size,
                StorageCategory::GalleryImage->value,
                $orphaned ? null : 'file_upload.path',
                $orphaned ? 0 : 1,
                $attributed ? TestDataFixture::PROJECT_1_ID : null,
                $attributed ? 'Projekt Alfa' : null,
                $attributed ? TestDataFixture::USER_1_ID : null,
                $attributed ? TestDataFixture::USER_1_EMAIL : null,
                $orphaned ? 'true' : 'false',
                '2026-08-03 10:00:00',
                Uuid::uuid7()->toString(),
            ],
        );
    }

    private function connection(KernelBrowser $browser): Connection
    {
        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);

        return $entityManager->getConnection();
    }
}
