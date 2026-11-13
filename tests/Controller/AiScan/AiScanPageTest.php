<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\AiScan;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use WBoost\Web\Services\AiScan\AiScanUrls;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\AiScan\AiScanMode;

/**
 * The wizard's page (docs/ai-scan/ux.md §5): `ai_scan_new` and `ai_scan_page`
 * — who gets it, what it hands the browser, and that nothing happens on a GET.
 * The screens themselves are drawn in the browser: tests/e2e/ai-scan.
 */
final class AiScanPageTest extends WebTestCase
{
    use AiScanTesting;

    private const string PROJECT = TestDataFixture::PROJECT_1_ID;
    private const string NEW = '/project/' . self::PROJECT . '/sablony/z-obrazku';

    public function testAnonymousVisitorsAreSentToTheLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', self::NEW);
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));

        $client->request('GET', self::NEW . '/00000000-0000-0000-0000-00000000dead');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testWithoutTheEntitlementTheNewPageSendsTheEditorBackToTheTemplateList(): void
    {
        // The production state: mode off, the project not entitled.
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', self::NEW);
        self::assertResponseRedirects('/project/' . self::PROJECT . '/templates');
        self::assertSame(0, self::rowCount('ai_scan'));

        // … and the list says why the page did not open and where access is asked for.
        $flash = $client->followRedirect()->filter('.alert')->text();
        self::assertStringContainsString('AI sken v tomto projektu není zapnutý', $flash);
        self::assertStringContainsString('Požádat o přístup', $flash);
    }

    public function testAReadOnlyMemberIsRefused(): void
    {
        $client = self::createClient();
        self::entitle(self::PROJECT);
        self::switchOn();
        TestingLogin::logInAsUser($client, TestDataFixture::SHARED_USER_EMAIL);

        $client->request('GET', self::NEW);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnEntitledEditorGetsTheUploadPageWithItsFacts(): void
    {
        $client = self::createClient();
        self::entitle(self::PROJECT);
        self::switchOn();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $crawler = $client->request('GET', self::NEW);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $boot = self::boot($crawler);
        self::assertNull($boot['scan']);
        self::assertSame(self::PROJECT, self::dig($boot, 'project', 'id'));
        self::assertSame('enabled', self::dig($boot, 'entry', 'state'));
        self::assertTrue(self::dig($boot, 'entry', 'canStart'));
        self::assertFalse(self::dig($boot, 'entry', 'consent', 'given'));
        self::assertSame('/project/' . self::PROJECT . '/ai-scan', self::dig($boot, 'urls', 'start'));
        self::assertSame('/ai-scan/00000000-0000-0000-0000-000000000000', self::dig($boot, 'urls', 'status'));
        self::assertSame('/ai-scan/00000000-0000-0000-0000-000000000000/image/original', self::dig($boot, 'urls', 'image'));
        self::assertSame('/project/' . self::PROJECT . '/sablony/historie-skenu', self::dig($boot, 'urls', 'history'));

        // The first paint is the page's own head; the gallery picker sits outside the controller and outside any form.
        self::assertStringContainsString('Šablona z obrázku', $crawler->filter('.as-page h1')->text());
        self::assertCount(1, $crawler->filter('#aiScanGalleryModal'));
        self::assertCount(0, $crawler->filter('.as-page #aiScanGalleryModal'));
        self::assertCount(0, $crawler->filter('form #aiScanGalleryModal'));
        // Nothing happened on the GET.
        self::assertSame(0, self::rowCount('ai_scan'));
    }

    public function testAnAdminGetsThePageWithoutTheProjectsEntitlement(): void
    {
        $client = self::createClient();
        self::switchOn(adminOnly: true);
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $client->request('GET', self::NEW);
        self::assertResponseIsSuccessful();
        self::assertSame('admin_preview', self::dig(self::boot($crawler), 'entry', 'state'));
        self::assertTrue(self::dig(self::boot($crawler), 'admin'));
    }

    public function testAScanHasOneUrlAndCarriesItsRow(): void
    {
        $client = self::createClient();
        self::entitle(self::PROJECT);
        self::switchOn();
        $scanId = self::ready($client);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $crawler = $client->request('GET', self::NEW . '/' . $scanId);
        self::assertResponseIsSuccessful();

        $boot = self::boot($crawler);
        self::assertSame($scanId, self::dig($boot, 'scan', 'id'));
        self::assertSame('ready', self::dig($boot, 'scan', 'status'));
        self::assertTrue(self::dig($boot, 'scan', 'mine'));
        self::assertTrue(self::dig($boot, 'scan', 'hasProposal'));
        // Opening the page is not "seen" by itself and changes nothing: the browser says so with a POST.
        self::assertSame('ready', self::text($scanId, 'status'));
        self::assertSame('', self::text($scanId, 'seen_at'));

        // The route the e-mails and the MCP answers link to.
        $urls = self::getContainer()->get(AiScanUrls::class);
        self::assertStringEndsWith(self::NEW . '/' . $scanId, $urls->scan(self::repositoryScan($scanId)));
    }

    public function testAProposalCanBeFinishedAfterAiScanWasSwitchedOff(): void
    {
        $client = self::createClient();
        self::entitle(self::PROJECT);
        self::switchOn();
        $scanId = self::ready($client);
        self::entitle(self::PROJECT, false);
        self::switchOn(AiScanMode::Off);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $crawler = $client->request('GET', self::NEW . '/' . $scanId);
        self::assertResponseIsSuccessful();
        $boot = self::boot($crawler);
        self::assertSame('locked', self::dig($boot, 'entry', 'state'), 'the page says new scans cannot start (F12)');
        self::assertSame('ready', self::dig($boot, 'scan', 'status'));

        // …while the new page is closed again.
        $client->request('GET', self::NEW);
        self::assertResponseRedirects('/project/' . self::PROJECT . '/templates');
    }

    public function testSomebodyElsesScanAnotherProjectsScanAndAnUnknownOne(): void
    {
        $client = self::createClient();
        self::entitle(self::PROJECT);
        self::switchOn();
        $scanId = self::ready($client);

        // A read-only member of the project: USE is refused.
        TestingLogin::logInAsUser($client, TestDataFixture::SHARED_USER_EMAIL);
        $client->request('GET', self::NEW . '/' . $scanId);
        self::assertResponseStatusCodeSame(403);

        // The scan under another project's address does not exist.
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);
        $client->request('GET', '/project/' . TestDataFixture::PROJECT_2_ID . '/sablony/z-obrazku/' . $scanId);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', self::NEW . '/00000000-0000-0000-0000-00000000dead');
        self::assertResponseStatusCodeSame(404);

        // An admin may open anybody's scan (support).
        $crawler = $client->request('GET', self::NEW . '/' . $scanId);
        self::assertResponseIsSuccessful();
        self::assertFalse(self::dig(self::boot($crawler), 'scan', 'mine'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function boot(Crawler $crawler): array
    {
        $root = $crawler->filter('[data-controller="ai-scan-page"]');
        self::assertCount(1, $root);

        /** @var array<string, mixed> $boot */
        $boot = self::decoded($root->attr('data-ai-scan-page-boot-value'));

        return $boot;
    }

    private static function repositoryScan(string $scanId): \WBoost\Web\Entity\AiScan
    {
        $scan = self::getContainer()->get('doctrine')->getManager()->find(\WBoost\Web\Entity\AiScan::class, $scanId);
        self::assertInstanceOf(\WBoost\Web\Entity\AiScan::class, $scan);

        return $scan;
    }
}
