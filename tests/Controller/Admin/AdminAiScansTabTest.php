<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\AiScan\AiScanMode;

/**
 * The admin tab „AI skeny“ on /admin/usage (ux.md §9.4): who sees and writes
 * it, the operating strip's forms writing the runtime row, „Vypnuto“ asking
 * first and interrupting scans, and the page with nothing, with requests and
 * with scans.
 */
final class AdminAiScansTabTest extends WebTestCase
{
    use AiScanTesting;

    private const string PROJECT = TestDataFixture::PROJECT_1_ID;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testOnlyAnAdminWritesAndOnlyWithTheCsrfToken(): void
    {
        $this->client->request('POST', '/admin/ai-scan/runtime', ['mode' => 'on']);
        self::assertResponseRedirects('/login');

        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);
        $this->client->request('POST', '/admin/ai-scan/runtime', ['mode' => 'on']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/usage');
        self::assertResponseStatusCodeSame(403);

        TestingLogin::logInAsUser($this->client, TestDataFixture::ADMIN_USER_EMAIL);
        $this->client->request('POST', '/admin/ai-scan/runtime', ['_token' => 'nope', 'mode' => 'on']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/ai-scan/runtime');
        self::assertResponseStatusCodeSame(405);

        self::assertSame(0, self::rowCount('ai_scan_runtime', "mode <> 'off'"));
    }

    public function testTheTabRendersWithNothingInIt(): void
    {
        TestingLogin::logInAsUser($this->client, TestDataFixture::ADMIN_USER_EMAIL);
        $crawler = $this->client->request('GET', '/admin/usage');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form form'), 'no form inside another form');

        $tab = $crawler->filter('#st-tab-ai-skeny');
        self::assertStringContainsString('AI skeny', $tab->text());
        self::assertCount(0, $tab->filter('.pd-n'), 'no open request: no count');

        $pane = $crawler->filter('#st-ai-skeny');
        self::assertSame('Vypnuto', $pane->filter('.ai-op-mode .seg button.on')->text());
        self::assertSame('true', $pane->filter('#aiAdminOnly')->attr('aria-checked'));
        self::assertCount(0, $pane->filter('.ai-notice'), 'switched off on purpose: nothing is wrong');
        self::assertCount(0, $pane->filter('#aiScanOffModal'));
        self::assertCount(0, $pane->filter('#ai-zadosti'));
        self::assertStringContainsString('Ve frontě 0', $pane->filter('.ai-op-facts')->eq(0)->text());
        self::assertStringContainsString('skener se ještě neozval', $pane->text());
        self::assertStringContainsString('Dnes utraceno 0,00 $ z 20,00 $', $pane->text());
        self::assertSame('20', $pane->filter('input[name="daily_ceiling_usd"]')->attr('value'));
        self::assertSame('5', $pane->filter('input[name="project_ceiling_usd"]')->attr('value'));
        self::assertSame('1', $pane->filter('input[name="max_running"]')->attr('value'));
        self::assertStringContainsString('Zatím žádný sken.', $pane->filter('#ai-skeny-tabulka')->text());

        // Every project is there with its switch — none is on.
        self::assertCount(0, $pane->filter('#ai-projekty button.sw[aria-checked="true"]'));
        self::assertGreaterThan(0, $pane->filter('#ai-projekty details button.sw[aria-checked="false"]')->count());
    }

    public function testTheStripWritesTheRuntimeRow(): void
    {
        TestingLogin::logInAsUser($this->client, TestDataFixture::ADMIN_USER_EMAIL);
        $crawler = $this->client->request('GET', '/admin/usage');

        // Mode: effective at once.
        $this->client->submit($crawler->filter('.ai-op-mode button[value="on"]')->form());
        self::assertResponseRedirects('/admin/usage#ai-skeny');
        self::assertSame('on', self::runtimeValue('mode'));
        $crawler = $this->client->followRedirect();
        self::assertSame('Zapnuto', $crawler->filter('.ai-op-mode .seg button.on')->text());
        self::assertStringContainsString('Provoz AI skenu: Zapnuto.', $crawler->text());
        self::assertCount(1, $crawler->filter('.ai-notice[data-notice="scanner_absent"]'), 'on, and no scanner calls');
        self::assertCount(1, $crawler->filter('#aiScanOffModal form'));

        $this->client->submit($crawler->filter('.ai-op-mode button[value="paused"]')->form());
        self::assertSame('paused', self::runtimeValue('mode'));
        $crawler = $this->client->followRedirect();
        self::assertSame('Pozastaveno', $crawler->filter('.ai-op-mode .seg button.on')->text());

        // "Jen administrátoři".
        self::assertTrue(self::runtimeValue('admin_only'));
        $this->client->submit($crawler->filter('form.ai-op-admin')->form());
        self::assertFalse(self::runtimeValue('admin_only'));
        self::assertSame('paused', self::runtimeValue('mode'), 'only what was sent changes');
        $crawler = $this->client->followRedirect();
        self::assertSame('false', $crawler->filter('#aiAdminOnly')->attr('aria-checked'));
        $this->client->submit($crawler->filter('form.ai-op-admin')->form());
        self::assertTrue(self::runtimeValue('admin_only'));
        $crawler = $this->client->followRedirect();

        // The three numbers (a decimal comma is fine).
        $this->client->submit($crawler->filter('form.ai-op-nums')->form([
            'daily_ceiling_usd' => '12,5',
            'project_ceiling_usd' => '3',
            'max_running' => '2',
        ]));
        self::assertSame(12_500_000, self::runtimeValue('daily_ceiling_micro_usd'));
        self::assertSame(3_000_000, self::runtimeValue('project_daily_micro_usd'));
        self::assertSame(2, self::runtimeValue('max_running'));
        $crawler = $this->client->followRedirect();
        self::assertSame('12.50', $crawler->filter('input[name="daily_ceiling_usd"]')->attr('value'));
        self::assertStringContainsString('Nastavení AI skenu uloženo.', $crawler->text());

        // Nonsense is an answer, and changes nothing.
        $token = (string) $crawler->filter('form.ai-op-nums input[name="_token"]')->attr('value');

        foreach ([
            ['daily_ceiling_usd' => 'hodně', 'project_ceiling_usd' => '3', 'max_running' => '2'],
            ['daily_ceiling_usd' => '5000', 'project_ceiling_usd' => '3', 'max_running' => '2'],
            ['daily_ceiling_usd' => '20', 'project_ceiling_usd' => '3', 'max_running' => '0'],
            ['mode' => 'sometimes'],
        ] as $fields) {
            $this->client->request('POST', '/admin/ai-scan/runtime', ['_token' => $token] + $fields);
            self::assertResponseRedirects('/admin/usage#ai-skeny');
            self::assertStringContainsString('Nic jsme nezměnili.', $this->client->followRedirect()->text());
        }

        self::assertSame(12_500_000, self::runtimeValue('daily_ceiling_micro_usd'));
        self::assertSame(2, self::runtimeValue('max_running'));
        self::assertSame('paused', self::runtimeValue('mode'));
    }

    public function testSwitchingOffAsksFirstAndInterruptsScansInProgress(): void
    {
        self::entitle(self::PROJECT);
        self::switchOn();
        $scanId = self::start()->toString();

        TestingLogin::logInAsUser($this->client, TestDataFixture::ADMIN_USER_EMAIL);
        $crawler = $this->client->request('GET', '/admin/usage');
        self::assertStringContainsString('teď je to 1 sken', $crawler->filter('#aiScanOffModal')->text());
        self::assertSame('modal', $crawler->filter('.ai-op-mode .seg button')->eq(2)->attr('data-bs-toggle'), '„Vypnuto“ opens the dialog, it does not submit');

        // Without the confirmation nothing happens.
        $token = (string) $crawler->filter('.ai-op-mode input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/ai-scan/runtime', ['_token' => $token, 'mode' => 'off']);
        self::assertResponseRedirects('/admin/usage#ai-skeny');
        self::assertSame('on', self::runtimeValue('mode'));
        self::assertSame('checking', self::text($scanId, 'status'));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('potvrďte ho prosím v dialogu', $crawler->text());

        // The dialog's own form.
        $this->client->submit($crawler->filter('#aiScanOffModal form')->form());
        self::assertResponseRedirects('/admin/usage#ai-skeny');
        self::assertSame('off', self::runtimeValue('mode'));
        self::assertSame('failed', self::text($scanId, 'status'));
        self::assertSame('interrupted', self::text($scanId, 'error_code'));
        self::assertSame(['1:reserve:-1', '1:refund:1'], self::ledger($scanId));

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('AI sken vypnut. Běžící a čekající skeny jsme přerušili a nezapočítali.', $crawler->text());
        self::assertSame('Vypnuto', $crawler->filter('.ai-op-mode .seg button.on')->text());

        $row = $crawler->filter(sprintf('#ai-skeny-tabulka tr[data-scan="%s"]', $scanId));
        self::assertStringContainsString('Nezdařil se', $row->text());
        self::assertStringContainsString('interrupted', $row->text());
        self::assertStringContainsString('vrácen', $row->text());
    }

    public function testTheTabListsRequestsProjectsAndScans(): void
    {
        // A request for a project that is off …
        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_2_EMAIL);
        $this->client->request(
            'POST',
            sprintf('/project/%s/ai-scan/access-request', TestDataFixture::PROJECT_2_ID),
            ['_token' => 'csrf-token', 'origin' => 'templates', 'message' => 'Chceme převést <b>letáky</b>.'],
            server: ['HTTP_ORIGIN' => 'http://localhost'],
        );
        self::assertResponseStatusCodeSame(201);

        // … and two scans in one that is on: a proposal and one that waits.
        self::entitle(self::PROJECT);
        self::switchOn(AiScanMode::On);
        $ready = self::ready($this->client);
        $waiting = self::start()->toString();

        TestingLogin::logInAsUser($this->client, TestDataFixture::ADMIN_USER_EMAIL);
        $crawler = $this->client->request('GET', '/admin/usage');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form form'));
        self::assertSame('1', $crawler->filter('#st-tab-ai-skeny .pd-n')->text());

        $pane = $crawler->filter('#st-ai-skeny');
        self::assertCount(0, $pane->filter('.ai-notice'), 'on, the scanner called a moment ago');
        self::assertStringContainsString('Ve frontě 1', $pane->filter('.ai-op-facts')->eq(0)->text());
        self::assertStringContainsString('(verze test-1)', $pane->filter('.ai-op-facts')->eq(0)->text());
        self::assertStringContainsString('poskytovatel v pořádku', $pane->filter('.ai-op-facts')->eq(0)->text());

        // The request: the same JSON endpoints the edit-project page uses.
        $request = $pane->filter('#ai-zadosti .as-req[data-controller="ai-scan-admin"]');
        self::assertCount(1, $request);
        self::assertStringContainsString('žádá o AI sken v projektu', $request->text());
        self::assertStringContainsString('„Chceme převést <b>letáky</b>.“', $request->text());
        self::assertMatchesRegularExpression('#^/admin/ai-scan/access-request/[0-9a-f-]{36}/approve$#', (string) $request->filter('button[data-decision="approve"]')->attr('data-url'));
        self::assertMatchesRegularExpression('#^/admin/ai-scan/access-request/[0-9a-f-]{36}/dismiss$#', (string) $request->filter('button[data-decision="dismiss"]')->attr('data-url'));

        // The project: on, one counted scan this month, its cost.
        $project = $pane->filter(sprintf('#ai-projekty > .ai-wrap form[action="/project/%s/ai-scan/entitlement"]', self::PROJECT))->closest('tr');
        self::assertNotNull($project);
        self::assertSame('true', $project->filter('button.sw')->attr('aria-checked'));
        self::assertSame('2', $project->filter('td[data-l="Tento měsíc"]')->text(), 'the proposal counts, the waiting scan is reserved');
        self::assertSame('10', $project->filter('td[data-l="Limit"]')->text());
        self::assertSame('1', $project->filter('td[data-l="Celkem"]')->text());
        self::assertStringContainsString('$', $project->filter('td[data-l="Náklady"]')->text());

        // The scans: in progress first.
        $rows = $pane->filter('#ai-skeny-tabulka tbody tr');
        self::assertCount(2, $rows);
        self::assertSame($waiting, $rows->eq(0)->attr('data-scan'));
        self::assertSame('ai-live', $rows->eq(0)->attr('class'));
        self::assertStringContainsString('Kontrola', $rows->eq(0)->text());
        self::assertStringContainsString('rezervován', $rows->eq(0)->text());

        $row = $rows->eq(1);
        self::assertSame($ready, $row->attr('data-scan'));
        self::assertStringContainsString('Návrh připraven', $row->text());
        self::assertStringContainsString('započten', $row->text());
        self::assertStringContainsString(TestDataFixture::USER_1_EMAIL, $row->text());
        self::assertStringContainsString('Šablony', $row->text());
        self::assertStringContainsString('web', $row->text());
        self::assertStringContainsString('$', $row->filter('td[data-l="Náklady"]')->text());
        self::assertNotSame('—', $row->filter('td[data-l="Tokeny"]')->text());
        self::assertSame(sprintf('/project/%s/sablony/z-obrazku/%s', self::PROJECT, $ready), $row->filter('.ai-acts a')->eq(0)->attr('href'));
        self::assertSame('/project/' . self::PROJECT, $row->filter('.ai-acts a')->eq(1)->attr('href'));
        self::assertStringNotContainsString('Headline', $pane->text(), 'nothing of the picture');

        // "Otevřít sken": the admin gets the scan's page.
        $this->client->request('GET', (string) $row->filter('.ai-acts a')->eq(0)->attr('href'));
        self::assertResponseIsSuccessful();

        // The project's switch comes back to this tab.
        $this->client->submit($project->filter('form')->form());
        self::assertResponseRedirects('/admin/usage#ai-skeny');
        self::assertFalse(self::connection()->fetchOne('SELECT ai_scan_enabled FROM project WHERE id = :id', ['id' => self::PROJECT]));
        self::assertSame('cancelled', self::text($waiting, 'status'), 'entitlement off cancels what waits');
    }
}
