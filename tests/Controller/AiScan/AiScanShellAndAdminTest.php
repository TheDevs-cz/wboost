<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\AiScan;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * The shell's AI scan signals (ring, count, dot, the poll switch), the access
 * requests on the admin's edit-project page and the switch on the admin's
 * user page (docs/ai-scan/ux.md §7, §9.2, §9.3).
 */
final class AiScanShellAndAdminTest extends WebTestCase
{
    use AiScanTesting;

    private const string PROJECT = TestDataFixture::PROJECT_1_ID;
    private const string TEMPLATES = '/project/' . self::PROJECT . '/templates';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::switchOn();
        self::entitle(self::PROJECT);
    }

    public function testAUserWithoutScansGetsNoSignalAndNoPoll(): void
    {
        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);

        $crawler = $this->client->request('GET', self::TEMPLATES);
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('[data-ai-scan-signal]'));
        self::assertCount(0, $crawler->filter('.as-ring, .count.as-pch, .as-ndot'));
        self::assertSame('Otevřít menu', $crawler->filter('.mbar [data-action~="shell#openDrawer"]')->attr('aria-label'));

        $shell = $crawler->filter('[data-controller="ai-scan-shell"]');
        self::assertCount(1, $shell);
        self::assertSame('false', $shell->attr('data-ai-scan-shell-poll-value'));
        self::assertSame('[]', $shell->attr('data-ai-scan-shell-known-value'));
        self::assertSame('/ai-scans/mine', $shell->attr('data-ai-scan-shell-url-value'));
    }

    public function testAVisitorGetsNothingOfIt(): void
    {
        $crawler = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-controller~="ai-scan-shell"], [data-ai-scan-signal]'));
    }

    public function testAnUnfinishedScanShowsTheRingAndSwitchesThePollOn(): void
    {
        $scanId = self::start(self::PROJECT, TestDataFixture::USER_1_EMAIL)->toString();
        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);

        $crawler = $this->client->request('GET', self::TEMPLATES);
        self::assertResponseIsSuccessful();

        $item = $crawler->filter('.sb [data-ai-scan-nav]');
        self::assertCount(1, $item->filter('.as-ring[role="img"][aria-label="Sken běží"]'));
        self::assertCount(1, $item->filter('.as-ndot'));
        self::assertCount(0, $item->filter('.count.as-pch'));
        self::assertStringContainsString('sken čeká na vaši pozornost', (string) $crawler->filter('.mbar [data-action~="shell#openDrawer"]')->attr('aria-label'));
        self::assertCount(1, $crawler->filter('.mbar [data-action~="shell#openDrawer"] .as-ndot'));

        $shell = $crawler->filter('[data-controller="ai-scan-shell"]');
        self::assertSame('true', $shell->attr('data-ai-scan-shell-poll-value'));
        self::assertSame([$scanId], json_decode((string) $shell->attr('data-ai-scan-shell-known-value'), true));
        self::assertSame(self::PROJECT, $shell->attr('data-ai-scan-shell-project-id-value'));

        // Somebody else's scan is not this viewer's signal.
        TestingLogin::logInAsUser($this->client, TestDataFixture::ADMIN_USER_EMAIL);
        $crawler = $this->client->request('GET', self::TEMPLATES);
        self::assertCount(0, $crawler->filter('[data-ai-scan-signal]'));
        self::assertSame('false', $crawler->filter('[data-controller="ai-scan-shell"]')->attr('data-ai-scan-shell-poll-value'));
    }

    public function testAnUnseenProposalShowsTheCountUntilItIsSeen(): void
    {
        $scanId = self::ready($this->client, self::PROJECT, TestDataFixture::USER_1_EMAIL);
        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);

        $crawler = $this->client->request('GET', self::TEMPLATES);
        $item = $crawler->filter('.sb [data-ai-scan-nav]');
        self::assertCount(0, $item->filter('.as-ring'));
        self::assertSame('1', $item->filter('.count.as-pch [aria-hidden="true"]')->text());
        self::assertSame('1 sken čeká na vaši pozornost', $item->filter('.count.as-pch .sr')->text());
        // Nothing is in progress: the badge is drawn by the server, the page does not poll.
        self::assertSame('false', $crawler->filter('[data-controller="ai-scan-shell"]')->attr('data-ai-scan-shell-poll-value'));

        $this->client->request('POST', sprintf('/ai-scan/%s/seen', $scanId), ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', self::TEMPLATES);
        self::assertCount(0, $crawler->filter('[data-ai-scan-signal]'));
    }

    public function testTheAdminSeesAProjectsOpenRequestsAndResolvesThem(): void
    {
        self::entitle(self::PROJECT, false);
        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);
        $this->client->request('POST', sprintf('/project/%s/ai-scan/access-request', self::PROJECT), ['_token' => 'csrf-token', 'origin' => 'templates', 'message' => 'Chceme převést <b>letáky</b>.'], server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(201);

        // The owner of the project sees none of it.
        $crawler = $this->client->request('GET', '/edit-project/' . self::PROJECT);
        self::assertCount(0, $crawler->filter('.as-req'));

        TestingLogin::logInAsUser($this->client, TestDataFixture::ADMIN_USER_EMAIL);
        $crawler = $this->client->request('GET', '/edit-project/' . self::PROJECT);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form form'));

        $row = $crawler->filter('#project-ai-scan [data-controller="ai-scan-admin"] .as-req');
        self::assertCount(1, $row);
        self::assertStringContainsString('žádá o AI sken', $row->filter('.who b')->text());
        self::assertStringContainsString('„Chceme převést <b>letáky</b>.“', $row->filter('.who span')->text());
        self::assertStringContainsString(TestDataFixture::USER_1_EMAIL, $row->text());

        $approve = (string) $row->filter('button[data-decision="approve"]')->attr('data-url');
        $dismiss = (string) $row->filter('button[data-decision="dismiss"]')->attr('data-url');
        self::assertMatchesRegularExpression('#^/admin/ai-scan/access-request/[0-9a-f-]{36}/approve$#', $approve);
        self::assertMatchesRegularExpression('#^/admin/ai-scan/access-request/[0-9a-f-]{36}/dismiss$#', $dismiss);

        // "Zapnout" — what the button posts.
        $this->client->request('POST', $approve, ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/edit-project/' . self::PROJECT);
        self::assertCount(0, $crawler->filter('.as-req'));
        self::assertSelectorTextContains('#project-ai-scan .pd-st', 'Zapnuto');
    }

    public function testTheUserPageSwitchesAnOwnedProjectsAiScanThroughAFormAnchor(): void
    {
        self::entitle(self::PROJECT, false);
        TestingLogin::logInAsUser($this->client, TestDataFixture::ADMIN_USER_EMAIL);
        $page = sprintf('/admin/users/%s/edit', TestDataFixture::USER_1_ID);

        $crawler = $this->client->request('GET', $page);
        self::assertResponseIsSuccessful();

        // Never a form inside the user form: the switch is a button with a form= anchor.
        self::assertCount(0, $crawler->filter('form form'));
        $switches = $crawler->filter('#user-projects .share-row button.sw[role="switch"][form]');
        self::assertGreaterThan(0, $switches->count());
        self::assertStringContainsString('AI sken se zapíná pro projekt', $crawler->filter('#user-projects')->text());
        // Shared (not owned) projects carry no switch.
        self::assertCount($crawler->filter('#user-projects .share-row .pill.pch')->count(), $switches);

        $formId = null;
        foreach ($switches as $switch) {
            assert($switch instanceof \DOMElement);
            $candidate = $crawler->filter('form#' . $switch->getAttribute('form'));
            self::assertCount(1, $candidate);
            self::assertCount(0, $candidate->ancestors()->reduce(static fn (\Symfony\Component\DomCrawler\Crawler $node): bool => $node->nodeName() === 'form'));

            if (str_contains((string) $candidate->attr('action'), self::PROJECT)) {
                $formId = $switch->getAttribute('form');
                self::assertSame('false', $switch->getAttribute('aria-checked'));
            }
        }
        self::assertNotNull($formId);

        $this->client->submit($crawler->filter('form#' . $formId)->form());
        self::assertResponseRedirects($page . '#user-projects');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('AI sken zapnut pro projekt', $crawler->text());
        self::assertTrue(self::connection()->fetchOne('SELECT ai_scan_enabled FROM project WHERE id = :id', ['id' => self::PROJECT]));
        self::assertSame('true', $crawler->filter(sprintf('button.sw[form="%s"]', $formId))->attr('aria-checked'));
        // The limit the project had is kept by the switch.
        self::assertSame(10, self::connection()->fetchOne('SELECT ai_scan_monthly_limit FROM project WHERE id = :id', ['id' => self::PROJECT]));
    }

    public function testTheSwitchNeverRedirectsToAnythingButAUserPage(): void
    {
        TestingLogin::logInAsUser($this->client, TestDataFixture::ADMIN_USER_EMAIL);
        $crawler = $this->client->request('GET', '/edit-project/' . self::PROJECT);
        $token = (string) $crawler->filter('#project-ai-scan .pd-mod-f input[name="_token"]')->attr('value');

        $this->client->request('POST', sprintf('/project/%s/ai-scan/entitlement', self::PROJECT), ['_token' => $token, 'enabled' => '1', 'return_user' => 'https://evil.example/']);
        self::assertResponseRedirects('/edit-project/' . self::PROJECT . '#project-ai-scan');
    }
}
