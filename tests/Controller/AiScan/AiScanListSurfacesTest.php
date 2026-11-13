<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\AiScan;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\AiScan\AiScanMode;

/**
 * The surfaces around a scan (docs/ai-scan/ux.md T1, T5, §8): the card in
 * "Nová šablona", the strip above the template list and "Historie skenů".
 *
 * The rollout rule lives here: with the feature in its production state
 * (mode `off`, nobody entitled) an editor sees the LOCKED card with a live
 * "Požádat o přístup", a viewer sees nothing of AI scan, and no page carries
 * a strip — so no page polls.
 */
final class AiScanListSurfacesTest extends WebTestCase
{
    use AiScanTesting;

    private const string PROJECT = TestDataFixture::PROJECT_1_ID;
    private const string TEMPLATES = '/project/' . self::PROJECT . '/templates';
    private const string HISTORY = '/project/' . self::PROJECT . '/sablony/historie-skenu';

    public function testProductionStateShowsTheLockedCardToAnEditorAndNothingElse(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $crawler = $client->request('GET', self::TEMPLATES);
        self::assertResponseIsSuccessful();

        $card = $crawler->filter('#templateNewModal .tp-type.prem');
        self::assertCount(1, $card);
        self::assertSame('locked', $card->attr('data-ai-scan-state'));
        self::assertSame('ai-scan-entry', $card->attr('data-controller'));
        self::assertSame('/project/' . self::PROJECT . '/ai-scan/access-request', $card->attr('data-ai-scan-entry-url-value'));
        self::assertStringContainsString('Požádat o přístup', $card->filter('button.tp-type-go')->text());
        self::assertNull($card->filter('button.tp-type-go')->attr('disabled'));
        self::assertStringNotContainsString('Brzy', $card->text());
        self::assertStringNotContainsString('z-obrazku', $card->html());

        // No scans → no strip, so nothing on the page polls.
        self::assertCount(0, $crawler->filter('[data-controller~="ai-scan-strip"]'));
        self::assertCount(0, $crawler->filter('.as-tp-pill'));
        // The other cards of the dialog are untouched.
        self::assertStringContainsString('Prázdná šablona', $crawler->filter('#templateNewModal')->text());
    }

    public function testAViewerWithoutEditRightsSeesNothingOfAiScan(): void
    {
        $client = self::createClient();
        self::entitle(self::PROJECT);
        self::switchOn();
        self::ready($client);
        TestingLogin::logInAsUser($client, TestDataFixture::SHARED_USER_EMAIL);

        $crawler = $client->request('GET', self::TEMPLATES);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#templateNewModal'));
        self::assertCount(0, $crawler->filter('.tp-type.prem'));
        self::assertCount(0, $crawler->filter('[data-controller~="ai-scan-strip"]'));
    }

    public function testAnEntitledEditorGetsTheEnabledCardAndTheStripWithItsScans(): void
    {
        $client = self::createClient();
        self::entitle(self::PROJECT);
        self::switchOn();
        $scanId = self::ready($client);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $crawler = $client->request('GET', self::TEMPLATES);
        self::assertResponseIsSuccessful();

        $card = $crawler->filter('#templateNewModal .tp-type.prem');
        self::assertSame('enabled', $card->attr('data-ai-scan-state'));
        self::assertNull($card->attr('data-controller'));
        self::assertSame('/project/' . self::PROJECT . '/sablony/z-obrazku', $card->filter('a.tp-type-go')->attr('href'));
        self::assertStringContainsString('Nahrát obrázek', $card->filter('a.tp-type-go')->text());
        self::assertStringContainsString('čeká na kontrolu', $card->filter('a[data-ai-scan-line="ready"]')->text());
        self::assertStringContainsString('/sablony/z-obrazku/' . $scanId, (string) $card->filter('a[data-ai-scan-line="ready"]')->attr('href'));
        self::assertStringContainsString('Historie skenů (1)', $card->text());

        $strip = $crawler->filter('[data-controller~="ai-scan-strip"]');
        self::assertCount(1, $strip);
        $rows = json_decode((string) $strip->attr('data-ai-scan-strip-scans-value'), true);
        self::assertIsArray($rows);
        self::assertCount(1, $rows);
        self::assertSame($scanId, self::dig($rows, 0, 'id'));
        self::assertSame('ready', self::dig($rows, 0, 'status'));
        self::assertStringContainsString('view=strip', (string) $strip->attr('data-ai-scan-strip-list-url-value'));
    }

    /**
     * @return iterable<string, array{AiScanMode, bool, null|int, string, string}>
     */
    public static function inertStates(): iterable
    {
        yield 'service waiting (paused)' => [AiScanMode::Paused, true, 10, 'service_waiting', 'AI sken je dočasně nedostupný. Víme o tom.'];
        yield 'service waiting (scanner absent)' => [AiScanMode::On, false, 10, 'service_waiting', 'AI sken je dočasně nedostupný. Víme o tom.'];
        yield 'limit reached' => [AiScanMode::On, true, 0, 'limit_reached', 'vyčerpáno 0 z 0 skenů'];
    }

    #[DataProvider('inertStates')]
    public function testInertStatesDisableTheUploadAndSayWhy(AiScanMode $mode, bool $scannerSeen, null|int $limit, string $state, string $line): void
    {
        $client = self::createClient();
        self::entitle(self::PROJECT, true, $limit);
        self::switchOn($mode, false, $scannerSeen);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $card = $client->request('GET', self::TEMPLATES)->filter('#templateNewModal .tp-type.prem');
        self::assertSame($state, $card->attr('data-ai-scan-state'));
        self::assertStringContainsString($line, $card->text());
        self::assertNotNull($card->filter('button.tp-type-go')->attr('disabled'));
        self::assertCount(0, $card->filter('a.tp-type-go'));
    }

    public function testASentRequestTurnsTheCardInert(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('POST', '/project/' . self::PROJECT . '/ai-scan/access-request', ['_token' => 'csrf-token', 'origin' => 'templates', 'message' => 'Plakáty'], server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(201);

        $card = $client->request('GET', self::TEMPLATES)->filter('#templateNewModal .tp-type.prem');
        self::assertSame('request_sent', $card->attr('data-ai-scan-state'));
        self::assertStringContainsString('Žádost odeslána', $card->text());
        self::assertStringContainsString('Dáme vám vědět.', $card->text());
        self::assertNull($card->attr('data-controller'));
    }

    public function testAnAdminOnAProjectWithoutAiScanSeesThePreviewCard(): void
    {
        $client = self::createClient();
        self::switchOn(AiScanMode::On, true);
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $card = $client->request('GET', self::TEMPLATES)->filter('#templateNewModal .tp-type.prem');
        self::assertSame('admin_preview', $card->attr('data-ai-scan-state'));
        self::assertStringContainsString('vidíte ho jako administrátor', $card->text());
        self::assertCount(1, $card->filter('a.tp-type-go'));
    }

    /**
     * @return iterable<string, array{null|string, int}>
     */
    public static function historyRoles(): iterable
    {
        yield 'admin' => [TestDataFixture::ADMIN_USER_EMAIL, 200];
        yield 'editor (owner)' => [TestDataFixture::USER_1_EMAIL, 200];
        yield 'read-only member' => [TestDataFixture::SHARED_USER_EMAIL, 403];
        yield 'stranger' => [TestDataFixture::USER_2_EMAIL, 403];
        yield 'anonymous' => [null, 302];
    }

    #[DataProvider('historyRoles')]
    public function testHistoryPermissions(null|string $email, int $expected): void
    {
        $client = self::createClient();

        if ($email !== null) {
            TestingLogin::logInAsUser($client, $email);
        }

        $client->request('GET', self::HISTORY);
        self::assertResponseStatusCodeSame($expected);

        if ($email === null) {
            self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
        }
    }

    /**
     * The wizard and the lists post JSON (`api.post` in assets/ai-scan/kit.js),
     * not a form: the action must find the token there too.
     */
    public function testAScanActionTakesTheTokenFromAJsonBody(): void
    {
        $client = self::createClient();
        self::entitle(self::PROJECT);
        self::switchOn();
        $waiting = self::start();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $server = ['HTTP_ORIGIN' => 'http://localhost', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        $client->request('POST', sprintf('/ai-scan/%s/cancel', $waiting), server: $server, content: '{"_token":"nope"}');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', sprintf('/ai-scan/%s/cancel', $waiting), server: $server, content: '{"_token":"csrf-token"}');
        self::assertResponseIsSuccessful();
        $answer = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($answer);
        self::assertIsArray($answer['scan'] ?? null);
        self::assertSame('cancelled', $answer['scan']['status'] ?? null);
    }

    public function testHistoryListsScansWithTheirPillsAndFilters(): void
    {
        $client = self::createClient();
        self::entitle(self::PROJECT);
        self::switchOn();
        $ready = self::ready($client);
        $waiting = self::start();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('POST', sprintf('/ai-scan/%s/cancel', $waiting), ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseIsSuccessful();

        $crawler = $client->request('GET', self::HISTORY);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Historie skenů', $crawler->filter('h1')->text());
        self::assertCount(2, $crawler->filter('.as-hrow'));
        self::assertStringContainsString('Nový sken', $crawler->filter('.head-tools')->text());

        $row = $crawler->filter(sprintf('.as-hrow[data-scan="%s"]', $ready));
        self::assertStringContainsString('Připraveno ke kontrole', $row->text());
        self::assertStringContainsString('vyprší', $row->text());
        self::assertStringContainsString('Otevřít návrh', $row->text());
        self::assertStringContainsString('Zahodit', $row->text());
        self::assertStringContainsString('/sablony/z-obrazku/' . $ready, (string) $row->filter('a.u-nm')->attr('href'));

        $cancelled = $crawler->filter(sprintf('.as-hrow[data-scan="%s"]', $waiting));
        self::assertStringContainsString('Zrušeno', $cancelled->text());

        $crawler = $client->request('GET', self::HISTORY . '?filter=waiting');
        self::assertCount(1, $crawler->filter('.as-hrow'));
        self::assertSame('Čekají na kontrolu', trim($crawler->filter('.fbar .chip.on')->text()));

        $crawler = $client->request('GET', self::HISTORY . '?filter=done');
        self::assertCount(0, $crawler->filter('.as-hrow'));
        self::assertStringContainsString('Tomuto filtru neodpovídá žádný sken', $crawler->filter('.empty')->text());
    }

    public function testEmptyHistoryInvitesToScanOnlyWhoCanStart(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $crawler = $client->request('GET', self::HISTORY);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Zatím tu není žádný sken', $crawler->filter('.empty')->text());
        self::assertStringNotContainsString('Nový sken', $crawler->filter('#view')->text());
    }
}
