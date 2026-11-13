<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Admin;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Services\AiScan\AiScanTransitions;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\AiScan\AiScanMode;
use WBoost\Web\Value\AiScan\AiScanRefused;

/**
 * The admin tab „AI skeny“ on /admin/usage (ux.md §9.4): who sees and writes
 * it, the operating strip's forms writing the runtime row, „Vypnuto“ asking
 * first and interrupting scans, the page with nothing, with requests and
 * with scans; the scans' row menu and rating dialog, paging, the two filters
 * by link (`?ai_scan=`, `?ai_project=` — a URL contract) and a project paused
 * for refusals.
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
        self::assertStringNotContainsString('Headline', $pane->text(), 'nothing of the picture');

        // The row's menu: a popover outside the table, opened by the row's button.
        self::assertSame('aiMenu-' . $ready, $row->filter('.ai-acts-menu button')->attr('popovertarget'));
        $menu = $pane->filter('#aiMenu-' . $ready);
        self::assertCount(1, $menu);
        self::assertCount(0, $menu->closest('table') ?? [], 'the menu is not inside the scrolling table');
        self::assertSame(['Otevřít sken', 'Otevřít projekt', 'Využití skenu'], $menu->filter('.mi')->each(static fn ($item): string => trim($item->text())));
        self::assertSame(sprintf('/project/%s/sablony/z-obrazku/%s', self::PROJECT, $ready), $menu->filter('[data-item="scan"]')->attr('href'));
        self::assertSame('/project/' . self::PROJECT, $menu->filter('[data-item="project"]')->attr('href'));
        self::assertSame(sprintf('/admin/usage?ai_scan=%s#ai-skeny', $ready), $menu->filter('[data-item="usage"]')->attr('href'));
        self::assertCount(0, $pane->filter('.ai-rating'), 'no rating: no dialog');
        self::assertCount(0, $pane->filter('.ai-pager'), 'one page: no pager');
        self::assertCount(0, $pane->filter('.ai-filter'));

        // "Otevřít sken": the admin gets the scan's page.
        $this->client->request('GET', (string) $menu->filter('[data-item="scan"]')->attr('href'));
        self::assertResponseIsSuccessful();

        // The project's switch comes back to this tab.
        $this->client->submit($project->filter('form')->form());
        self::assertResponseRedirects('/admin/usage#ai-skeny');
        self::assertFalse(self::connection()->fetchOne('SELECT ai_scan_enabled FROM project WHERE id = :id', ['id' => self::PROJECT]));
        self::assertSame('cancelled', self::text($waiting, 'status'), 'entitlement off cancels what waits');
    }

    public function testTheRatingOpensInADialogFromTheRowMenu(): void
    {
        self::entitle(self::PROJECT);
        self::switchOn(AiScanMode::On);
        $bad = self::ready($this->client);
        $good = self::ready($this->client);
        self::connection()->executeStatement("UPDATE ai_scan SET rating = -1, rating_note = :note WHERE id = :id", ['id' => $bad, 'note' => "Texty jsou <i>jinde</i>.\nA chybí logo."]);
        self::connection()->executeStatement('UPDATE ai_scan SET rating = 1 WHERE id = :id', ['id' => $good]);

        TestingLogin::logInAsUser($this->client, TestDataFixture::ADMIN_USER_EMAIL);
        $crawler = $this->client->request('GET', '/admin/usage');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form form'));

        foreach ([$bad => ['👎', 'Špatný návrh', 'Texty jsou <i>jinde</i>.'], $good => ['👍', 'Dobrý návrh', 'Bez poznámky.']] as $id => [$sign, $label, $note]) {
            $item = $crawler->filter(sprintf('#aiMenu-%s [data-item="rating"]', $id));
            self::assertSame('Zobrazit hodnocení', trim($item->text()));
            self::assertSame('button', $item->nodeName(), 'a dialog, not a navigation');
            self::assertSame('modal', $item->attr('data-bs-toggle'));
            self::assertSame('#aiRating-' . $id, $item->attr('data-bs-target'));
            self::assertSame('hide', $item->attr('popovertargetaction'), 'the menu closes when the dialog opens');

            $dialog = $crawler->filter('#aiRating-' . $id);
            self::assertCount(1, $dialog);
            self::assertCount(0, $dialog->closest('.st-card') ?? [], 'dialogs live outside the cards');
            self::assertStringContainsString($sign, $dialog->text());
            self::assertStringContainsString($label, $dialog->text());
            self::assertStringContainsString($note, $dialog->text());
            self::assertStringContainsString(TestDataFixture::USER_1_EMAIL, $dialog->text());
        }

        // The note is in the dialog, no longer a tooltip of the pill.
        self::assertNull($crawler->filter(sprintf('tr[data-scan="%s"] td[data-l="Hodnocení"] .pill', $bad))->attr('title'));
    }

    public function testEndedScansArePagedAndScansInProgressStayOnTheFirstPage(): void
    {
        self::entitle(self::PROJECT);
        self::switchOn(AiScanMode::On);
        $waiting = self::start()->toString();
        $ended = self::start()->toString();
        self::assertTrue(self::getContainer()->get(AiScanTransitions::class)->cancel($ended));
        // 1 + 119 copies = 120 ended scans: pages of 50, 50 and 20.
        $this->copyEndedScan($ended, 119);

        TestingLogin::logInAsUser($this->client, TestDataFixture::ADMIN_USER_EMAIL);

        $crawler = $this->client->request('GET', '/admin/usage');
        $rows = $crawler->filter('#ai-skeny-tabulka table.ai-scans tbody tr');
        self::assertCount(51, $rows, 'the waiting scan and 50 ended ones');
        self::assertSame($waiting, $rows->eq(0)->attr('data-scan'));
        self::assertSame('121', $crawler->filter('#ai-skeny-tabulka h2 .pd-n')->text());
        self::assertCount(51, $crawler->filter('#ai-skeny-tabulka .ai-menu'), 'a menu per row');
        $pager = $crawler->filter('#ai-skeny-tabulka .ai-pager');
        self::assertStringContainsString('Strana 1 z 3', $pager->text());
        self::assertCount(0, $pager->filter('a[rel="prev"]'), 'nothing newer than the first page');
        self::assertSame('/admin/usage?ai_page=2#ai-skeny', $pager->filter('a[rel="next"]')->attr('href'));
        $first = $rows->each(static fn ($row): string => (string) $row->attr('data-scan'));

        $crawler = $this->client->request('GET', '/admin/usage?ai_page=2');
        $rows = $crawler->filter('#ai-skeny-tabulka table.ai-scans tbody tr');
        self::assertCount(50, $rows, 'ended scans only');
        self::assertCount(0, $crawler->filter('#ai-skeny-tabulka tr.ai-live'));
        self::assertSame([], array_intersect($first, $rows->each(static fn ($row): string => (string) $row->attr('data-scan'))), 'no scan twice');
        $pager = $crawler->filter('#ai-skeny-tabulka .ai-pager');
        self::assertStringContainsString('Strana 2 z 3', $pager->text());
        self::assertSame('/admin/usage#ai-skeny', $pager->filter('a[rel="prev"]')->attr('href'), 'page 1 has no parameter');
        self::assertSame('/admin/usage?ai_page=3#ai-skeny', $pager->filter('a[rel="next"]')->attr('href'));

        $crawler = $this->client->request('GET', '/admin/usage?ai_page=3');
        self::assertCount(20, $crawler->filter('#ai-skeny-tabulka table.ai-scans tbody tr'));
        self::assertCount(0, $crawler->filter('#ai-skeny-tabulka .ai-pager a[rel="next"]'));

        // Out of range or nonsense: the nearest page, never an error.
        foreach (['ai_page=99' => 'Strana 3 z 3', 'ai_page=0' => 'Strana 1 z 3', 'ai_page=-4' => 'Strana 1 z 3', 'ai_page=dva' => 'Strana 1 z 3', 'ai_page[]=2' => 'Strana 1 z 3'] as $query => $expected) {
            $crawler = $this->client->request('GET', '/admin/usage?' . $query);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString($expected, $crawler->filter('#ai-skeny-tabulka .ai-pager')->text(), $query);
        }
    }

    public function testTheLinkToOneScanShowsItsRowAndEveryUsageRowOfIt(): void
    {
        self::entitle(self::PROJECT);
        self::switchOn(AiScanMode::On);
        $ready = self::ready($this->client);
        $other = self::start()->toString();

        TestingLogin::logInAsUser($this->client, TestDataFixture::ADMIN_USER_EMAIL);
        $crawler = $this->client->request('GET', sprintf('/admin/usage?ai_scan=%s', $ready));
        self::assertResponseIsSuccessful();

        $card = $crawler->filter('#ai-skeny-tabulka');
        self::assertSame([$ready], $card->filter('table.ai-scans tbody tr')->each(static fn ($row): string => (string) $row->attr('data-scan')), 'only that scan');
        self::assertStringContainsString($ready, $card->filter('.ai-filter[data-filter="scan"]')->text());
        self::assertSame('/admin/usage#ai-skeny', $card->filter('.ai-filter a')->attr('href'));
        self::assertSame('Zrušit filtr', trim($card->filter('.ai-filter a')->text()));
        self::assertCount(0, $card->filter('.ai-pager'));
        self::assertCount(0, $card->filter('[data-item="usage"]'), 'already there');

        // Every ai_usage_event row of the scan: run, step, model, tokens, cost, outcome.
        $expected = self::connection()->fetchAllAssociative('SELECT run, step, model, outcome FROM ai_usage_event WHERE scan_id = :id ORDER BY occurred_at, run, step', ['id' => $ready]);
        self::assertNotEmpty($expected);
        $usage = $card->filter('#ai-vyuziti tbody tr');
        self::assertCount(count($expected), $usage);

        foreach ($expected as $index => $event) {
            $line = $usage->eq($index);
            self::assertTrue(is_numeric($event['run']) && is_string($event['step']) && is_string($event['outcome']));
            self::assertSame($event['run'] . '-' . $event['step'], $line->attr('data-usage'));
            self::assertStringContainsString($event['outcome'], $line->filter('td[data-l="Výsledek"]')->text());
            self::assertStringContainsString('$', $line->filter('td[data-l="Náklady"]')->text());
            self::assertNotSame('', trim($line->filter('td[data-l="Vstup"]')->text()));
        }

        self::assertStringContainsString('test-model', $card->filter('#ai-vyuziti')->text());
        self::assertStringContainsString('Celkem za sken', $card->filter('#ai-vyuziti')->text());
        self::assertStringNotContainsString('Headline', $card->text(), 'nothing of the picture');

        // The usage outlives the scan row.
        self::connection()->executeStatement('DELETE FROM ai_scan WHERE id = :id', ['id' => $ready]);
        $crawler = $this->client->request('GET', sprintf('/admin/usage?ai_scan=%s', $ready));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Sken už neexistuje.', $crawler->filter('#ai-skeny-tabulka')->text());
        self::assertCount(count($expected), $crawler->filter('#ai-vyuziti tbody tr'));

        // A scan nobody knows; and what is not a uuid is ignored.
        $crawler = $this->client->request('GET', '/admin/usage?ai_scan=018f0000-0000-7000-8000-000000000000');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Sken s tímto ID neexistuje.', $crawler->filter('#ai-skeny-tabulka')->text());
        self::assertCount(0, $crawler->filter('#ai-vyuziti'));

        foreach (['ai_scan=nope', 'ai_scan=', 'ai_scan[]=' . $other, "ai_scan=%27%20OR%201=1"] as $query) {
            $crawler = $this->client->request('GET', '/admin/usage?' . $query);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('.ai-filter'), $query);
            self::assertCount(1, $crawler->filter(sprintf('tr[data-scan="%s"]', $other)), $query);
        }
    }

    public function testTheLinkToOneProjectNarrowsTheScansAndKeepsThePaging(): void
    {
        self::entitle(self::PROJECT);
        self::switchOn(AiScanMode::On);
        $transitions = self::getContainer()->get(AiScanTransitions::class);
        $mine = self::start()->toString();
        self::assertTrue($transitions->cancel($mine));
        $this->copyEndedScan($mine, 54);
        $waiting = self::start()->toString();
        // One ended scan of another project (which has no fonts to start one with).
        $foreign = $this->copyEndedScan($mine, 1, TestDataFixture::PROJECT_2_ID);

        TestingLogin::logInAsUser($this->client, TestDataFixture::ADMIN_USER_EMAIL);

        // Without the filter both projects are there; the project's name is the link.
        $crawler = $this->client->request('GET', '/admin/usage');
        self::assertCount(1, $crawler->filter(sprintf('tr[data-scan="%s"]', $foreign)));
        self::assertSame(
            sprintf('/admin/usage?ai_project=%s#ai-skeny', TestDataFixture::PROJECT_2_ID),
            $crawler->filter(sprintf('tr[data-scan="%s"] .ai-main a', $foreign))->attr('href'),
        );
        self::assertSame(
            sprintf('/admin/usage?ai_project=%s#ai-skeny', self::PROJECT),
            $crawler->filter(sprintf('#ai-projekty form[action="/project/%s/ai-scan/entitlement"]', self::PROJECT))->closest('tr')?->filter('.ai-acts a')->attr('href'),
        );

        $crawler = $this->client->request('GET', sprintf('/admin/usage?ai_project=%s', self::PROJECT));
        self::assertResponseIsSuccessful();
        $card = $crawler->filter('#ai-skeny-tabulka');
        self::assertCount(0, $card->filter(sprintf('tr[data-scan="%s"]', $foreign)));
        self::assertCount(51, $card->filter('table.ai-scans tbody tr'));
        self::assertSame($waiting, $card->filter('table.ai-scans tbody tr')->eq(0)->attr('data-scan'));
        self::assertSame('56', $card->filter('h2 .pd-n')->text(), 'the scans of the project');
        self::assertCount(1, $card->filter('.ai-filter[data-filter="project"]'));
        self::assertSame('/admin/usage#ai-skeny', $card->filter('.ai-filter a')->attr('href'));
        self::assertStringContainsString('Strana 1 z 2', $card->filter('.ai-pager')->text());
        self::assertSame(sprintf('/admin/usage?ai_project=%s&ai_page=2#ai-skeny', self::PROJECT), $card->filter('.ai-pager a[rel="next"]')->attr('href'));

        $crawler = $this->client->request('GET', sprintf('/admin/usage?ai_project=%s&ai_page=2', self::PROJECT));
        $card = $crawler->filter('#ai-skeny-tabulka');
        self::assertCount(5, $card->filter('table.ai-scans tbody tr'));
        self::assertSame(sprintf('/admin/usage?ai_project=%s#ai-skeny', self::PROJECT), $card->filter('.ai-pager a[rel="prev"]')->attr('href'));

        // The other project: one scan, no pager.
        $crawler = $this->client->request('GET', sprintf('/admin/usage?ai_project=%s', TestDataFixture::PROJECT_2_ID));
        self::assertSame([$foreign], $crawler->filter('#ai-skeny-tabulka table.ai-scans tbody tr')->each(static fn ($row): string => (string) $row->attr('data-scan')));
        self::assertCount(0, $crawler->filter('#ai-skeny-tabulka .ai-pager'));

        // An unknown project or nonsense is no filter.
        foreach (['ai_project=018f0000-0000-7000-8000-000000000000', 'ai_project=x', 'ai_project[]=' . self::PROJECT] as $query) {
            $crawler = $this->client->request('GET', '/admin/usage?' . $query);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('.ai-filter'), $query);
            self::assertCount(1, $crawler->filter(sprintf('tr[data-scan="%s"]', $foreign)), $query);
        }

        // The admin's box on the edit-project page links here (ux.md §9.2 "Využití →").
        $crawler = $this->client->request('GET', '/edit-project/' . self::PROJECT);
        self::assertResponseIsSuccessful();
        self::assertSame(sprintf('/admin/usage?ai_project=%s#ai-skeny', self::PROJECT), $crawler->filter('#project-ai-scan a.pai-usage')->attr('href'));
        self::assertCount(0, $crawler->filter('form form'));
    }

    public function testAProjectPausedForRefusalsIsMarked(): void
    {
        self::entitle(self::PROJECT);
        self::switchOn(AiScanMode::On);
        TestingLogin::logInAsUser($this->client, TestDataFixture::ADMIN_USER_EMAIL);

        $this->refusals(2);
        $crawler = $this->client->request('GET', '/admin/usage');
        self::assertCount(0, $crawler->filter('#ai-projekty [data-paused]'), 'two refusals pause nothing');

        $this->refusals(1);
        $crawler = $this->client->request('GET', '/admin/usage');
        $pill = $crawler->filter('#ai-projekty [data-paused]');
        self::assertCount(1, $pill);
        self::assertSame('Pozastaveno do zítřka · 3 odmítnutí', trim($pill->text()));
        self::assertNotNull($pill->closest('tr')?->filter(sprintf('form[action="/project/%s/ai-scan/entitlement"]', self::PROJECT))->getNode(0), 'on the paused project\'s row');

        // It is the start guard's count: the project really cannot start.
        try {
            self::start();
            self::fail('The project is paused.');
        } catch (AiScanRefused $refused) {
            self::assertSame('project_paused', $refused->refusal->value);
        }
    }

    /** Copies of an ended scan row, each a minute older — raw rows, as a purge-free history would hold. */
    private function copyEndedScan(string $scanId, int $copies, null|string $projectId = null): string
    {
        $id = '';
        $row = self::connection()->fetchAssociative('SELECT * FROM ai_scan WHERE id = :id', ['id' => $scanId]);
        self::assertIsArray($row);
        $finished = new DateTimeImmutable(is_string($row['finished_at']) ? $row['finished_at'] : 'now');

        for ($index = 1; $index <= $copies; ++$index) {
            $copy = $row;
            $copy['id'] = $id = Uuid::uuid7()->toString();
            $copy['project_id'] = $projectId ?? $copy['project_id'];
            $copy['request_id'] = bin2hex(random_bytes(8));
            $copy['finished_at'] = $finished->modify(sprintf('-%d minutes', $index))->format('Y-m-d H:i:s');

            foreach ($copy as $column => $value) {
                if (is_bool($value)) {
                    $copy[$column] = $value ? 'true' : 'false';
                }
            }

            self::connection()->insert('ai_scan', array_combine(array_map(static fn (string $column): string => sprintf('"%s"', $column), array_keys($copy)), array_values($copy)));
        }

        return $id;
    }

    /** Closed usage rows of today that ended in a refusal by the provider. */
    private function refusals(int $count): void
    {
        for ($index = 0; $index < $count; ++$index) {
            self::connection()->executeStatement(
                "INSERT INTO ai_usage_event (id, scan_id, run, step, occurred_at, closed_at, cost_micro_usd, outcome, project_id, project_name, owner_email, user_email, channel, kind)
                 VALUES (:id, :scan, 1, 'pre_check', NOW(), NOW(), 1000, 'refused', :project, 'Projekt', 'owner@example.com', 'user@example.com', 'web', 'template')",
                ['id' => Uuid::uuid7()->toString(), 'scan' => Uuid::uuid7()->toString(), 'project' => self::PROJECT],
            );
        }
    }
}
