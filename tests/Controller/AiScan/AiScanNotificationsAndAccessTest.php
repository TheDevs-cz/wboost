<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\AiScan;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use WBoost\Web\Message\AiScan\SendAiScanEmail;
use WBoost\Web\Services\AiScan\AiScanNotifications;
use WBoost\Web\Services\AiScan\AiScanPurge;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * Leaving and coming back (ux.md §7), the access request (§9.1) and the
 * lists (T1, T5, §8): one delayed e-mail that "seen" cancels, one reminder,
 * a stored and mailed request with one open per user and a daily limit.
 *
 * @phpstan-type Row array{id: string, status: string, name: null|string, mine: bool, seen: bool, actions: list<string>, hasProposal: bool}
 */
final class AiScanNotificationsAndAccessTest extends WebTestCase
{
    use AiScanTesting;

    private const string PROJECT = TestDataFixture::PROJECT_1_ID;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::entitle(self::PROJECT);
        self::switchOn();
    }

    public function testOneDelayedEmailWhenAScanEndsUnseen(): void
    {
        $scanId = self::ready($this->client);

        // The end of the scan queued ONE delayed message — nothing is sent yet.
        self::assertSame(1, self::rowCount('messenger_messages', sprintf("body LIKE '%%SendAiScanEmail%%' AND body LIKE '%%%s%%' AND available_at > created_at + INTERVAL '100 seconds'", $scanId)));

        $notifications = self::getContainer()->get(AiScanNotifications::class);
        self::assertFalse($notifications->sendEnded(Uuid::fromString($scanId)), 'not sooner than two minutes after the end');
        self::assertSame('', self::text($scanId, 'notified_at'));

        $this->backdate($scanId, 'finished_at', '-3 minutes');
        self::bus()->dispatch(new SendAiScanEmail(Uuid::fromString($scanId)));

        // Routed to the transport: the consumer's part is played by hand.
        self::assertTrue($notifications->sendEnded(Uuid::fromString($scanId)));
        self::assertNotSame('', self::text($scanId, 'notified_at'));
        self::assertEmailCount(1);

        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('Návrh šablony z obrázku je připravený', $mail->getSubject());
        self::assertSame(TestDataFixture::USER_1_EMAIL, $mail->getTo()[0]->getAddress());
        $html = (string) $mail->getHtmlBody();
        self::assertStringContainsString('design.png', $html);
        self::assertStringContainsString('„Headline“', $html);
        self::assertStringContainsString('Zbývají rozhodnout 3 věci.', $html);
        self::assertStringContainsString('Zkontrolovat návrh', $html);
        self::assertStringContainsString('/project/' . self::PROJECT, $html, 'the button only opens a page');
        self::assertStringNotContainsString(self::text($scanId, 'storage_secret'), $html);

        // Once.
        self::assertFalse($notifications->sendEnded(Uuid::fromString($scanId)));
        self::assertSame(0, $notifications->sendDue());
        self::assertEmailCount(1);
    }

    public function testSeenCancelsTheEmailAndOnlyTheRequestersLookCounts(): void
    {
        $scanId = self::ready($this->client);
        $notifications = self::getContainer()->get(AiScanNotifications::class);

        // Another editor (the admin) looking at it is not the requester seeing it.
        self::assertFalse($notifications->seen(Uuid::fromString($scanId), Uuid::fromString(TestDataFixture::ADMIN_USER_ID)));
        self::assertTrue($notifications->seen(Uuid::fromString($scanId), Uuid::fromString(TestDataFixture::USER_1_ID)));

        $this->backdate($scanId, 'finished_at', '-10 minutes');
        self::assertFalse($notifications->sendEnded(Uuid::fromString($scanId)));
        self::assertSame(0, $notifications->sendDue());
        self::assertEmailCount(0);
    }

    public function testAFailedScanAndAStoppedOneHaveTheirOwnMailAndAGoneAccountGetsNone(): void
    {
        $failed = self::running($this->client);
        self::scanner($this->client, 'POST', $failed['id'] . '/finish', ['run' => $failed['run'], 'outcome' => 'failed', 'error' => 'build', 'usage' => self::usage(1)], $failed['lease']);
        self::assertSame('failed', self::text($failed['id'], 'status'));

        $stoppedId = self::start()->toString();
        $intake = self::claim($this->client, 'intake');
        self::scanner($this->client, 'POST', $stoppedId . '/finish', ['run' => $intake['run'], 'outcome' => 'stopped', 'reason' => 'several_designs', 'usage' => self::usage(1)], $intake['lease']);
        self::assertSame('stopped', self::text($stoppedId, 'status'));

        $orphan = self::ready($this->client);
        self::connection()->executeStatement('UPDATE ai_scan SET requested_by_id = NULL WHERE id = :id', ['id' => $orphan]);

        foreach ([$failed['id'], $stoppedId, $orphan] as $id) {
            $this->backdate($id, 'finished_at', '-5 minutes');
        }

        // The purge's backstop sends what no delayed message sent.
        self::assertSame(2, self::getContainer()->get(AiScanPurge::class)->run()['emails']);
        self::assertEmailCount(2);

        $subjects = [];
        $bodies = '';

        foreach (self::getMailerMessages() as $mail) {
            self::assertInstanceOf(Email::class, $mail);
            $subjects[] = $mail->getSubject();
            $bodies .= (string) $mail->getHtmlBody();
        }

        $subjects = array_values(array_unique($subjects));
        sort($subjects);
        self::assertSame(['Sken potřebuje jiný obrázek', 'Sken se nepodařil'], $subjects);
        self::assertStringContainsString('Je v něm víc grafik.', $bodies);
        self::assertStringContainsString('návrh se nepodařilo poskládat', $bodies);
        self::assertStringContainsString('Nezapočítal se.', $bodies);
    }

    public function testOneReminderThreeDaysBeforeAProposalExpires(): void
    {
        $soon = self::ready($this->client);
        $later = self::ready($this->client);
        $notifications = self::getContainer()->get(AiScanNotifications::class);

        foreach ([$soon, $later] as $id) {
            $notifications->seen(Uuid::fromString($id), Uuid::fromString(TestDataFixture::USER_1_ID));
        }

        $this->backdate($soon, 'expires_at', '+2 days');
        $this->backdate($later, 'expires_at', '+10 days');

        self::assertSame(1, $notifications->sendDue(), 'a seen proposal is still reminded — it waits');
        self::assertNotSame('', self::text($soon, 'reminded_at'));
        self::assertSame('', self::text($later, 'reminded_at'));
        self::assertEmailCount(1);

        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('Návrh „Headline“ za 3 dny vyprší', $mail->getSubject());
        self::assertStringContainsString('čeká na kontrolu', (string) $mail->getHtmlBody());

        self::assertSame(0, $notifications->sendDue(), 'one reminder');
        self::assertFalse($notifications->sendReminder(Uuid::fromString($soon)));
        self::assertEmailCount(1);
    }

    public function testAccessRequestIsStoredMailedOnePerUserAndRateLimited(): void
    {
        self::entitle(self::PROJECT, false);
        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);

        self::assertSame('locked', $this->entry()['state']);

        $sent = $this->post(sprintf('/project/%s/ai-scan/access-request', self::PROJECT), ['origin' => 'calendars', 'message' => "  Chceme převést letáky.\x07  "]);
        self::assertResponseStatusCodeSame(201);
        self::assertTrue($sent['created']);
        self::assertSame(TestDataFixture::USER_1_EMAIL, $sent['email']);

        $row = self::connection()->fetchAssociative('SELECT * FROM ai_access_request');
        self::assertIsArray($row);
        self::assertSame(['open', 'calendars', 'Chceme převést letáky.', TestDataFixture::USER_1_EMAIL], [$row['status'], $row['origin'], $row['message'], $row['requester_email']]);

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertStringContainsString('Žádost o AI sken', (string) $mail->getSubject());
        self::assertStringContainsString('Chceme převést letáky.', (string) $mail->getHtmlBody());
        self::assertStringContainsString('#project-modules', (string) $mail->getHtmlBody(), 'a deep link to the switch');

        // One open request per user and project: the second answers with the first.
        $again = $this->post(sprintf('/project/%s/ai-scan/access-request', self::PROJECT));
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($again['created']);
        self::assertSame($sent['id'], $again['id']);
        self::assertSame(1, self::rowCount('ai_access_request'));

        $entry = $this->entry();
        self::assertSame('request_sent', $entry['state']);
        self::assertNotNull($entry['requestSentAt']);

        // Rate limit: five a day per user, counted in the table.
        self::connection()->executeStatement("UPDATE ai_access_request SET status = 'dismissed'");

        for ($i = 0; $i < 4; $i++) {
            self::connection()->executeStatement(
                "INSERT INTO ai_access_request (id, project_id, user_id, requester_email, requester_name, origin, status, created_at)
                 VALUES (:id, :project, :user, 'x@test.cz', 'X', 'templates', 'dismissed', NOW())",
                ['id' => Uuid::uuid7()->toString(), 'project' => self::PROJECT, 'user' => TestDataFixture::USER_1_ID],
            );
        }

        $limited = $this->post(sprintf('/project/%s/ai-scan/access-request', self::PROJECT));
        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $limited['code']);
        self::assertSame(5, self::rowCount('ai_access_request'));
    }

    public function testAnAdminApprovesOrDismissesOnce(): void
    {
        self::entitle(self::PROJECT, false);
        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);
        $first = $this->post(sprintf('/project/%s/ai-scan/access-request', self::PROJECT));

        TestingLogin::logInAsUser($this->client, TestDataFixture::ADMIN_USER_EMAIL);
        $this->client->request('GET', '/admin/ai-scan/access-requests?projectId=' . self::PROJECT);
        /** @var array{requests: list<array{id: string, requesterEmail: string}>} $open */
        $open = self::decoded((string) $this->client->getResponse()->getContent());
        self::assertSame([$first['id']], array_column($open['requests'], 'id'));

        $this->post(sprintf('/admin/ai-scan/access-request/%s/dismiss', $first['id']));
        self::assertResponseIsSuccessful();
        self::assertSame('dismissed', self::connection()->fetchOne('SELECT status FROM ai_access_request WHERE id = :id', ['id' => $first['id']]));
        self::assertFalse(self::connection()->fetchOne('SELECT ai_scan_enabled FROM project WHERE id = :id', ['id' => self::PROJECT]));
        self::assertSame('wrong_state', $this->post(sprintf('/admin/ai-scan/access-request/%s/approve', $first['id']))['code'], 'resolved once');

        // A new request, approved: the project is switched on and the requester mailed.
        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);
        $second = $this->post(sprintf('/project/%s/ai-scan/access-request', self::PROJECT));
        self::assertTrue($second['created']);

        TestingLogin::logInAsUser($this->client, TestDataFixture::ADMIN_USER_EMAIL);
        $this->post(sprintf('/admin/ai-scan/access-request/%s/approve', $second['id']));
        self::assertResponseIsSuccessful();
        self::assertTrue(self::connection()->fetchOne('SELECT ai_scan_enabled FROM project WHERE id = :id', ['id' => self::PROJECT]));
        self::assertSame(10, self::connection()->fetchOne('SELECT ai_scan_monthly_limit FROM project WHERE id = :id', ['id' => self::PROJECT]));

        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('AI sken je zapnutý', $mail->getSubject());
        self::assertSame(TestDataFixture::USER_1_EMAIL, $mail->getTo()[0]->getAddress());
        self::assertStringContainsString('Vyzkoušet', (string) $mail->getHtmlBody());

        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);
        self::assertSame('enabled', $this->entry()['state']);
    }

    public function testListsEntryAndTheBadge(): void
    {
        $ready = self::ready($this->client);
        $failed = self::running($this->client);
        self::scanner($this->client, 'POST', $failed['id'] . '/finish', ['run' => $failed['run'], 'outcome' => 'failed', 'error' => 'timeout', 'usage' => self::usage(1)], $failed['lease']);
        $waiting = self::start()->toString();

        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);

        $history = $this->scans('/project/' . self::PROJECT . '/ai-scans');
        self::assertSame([$waiting, $failed['id'], $ready], array_column($history, 'id'), 'newest first');
        self::assertSame(['checking', 'failed', 'ready'], array_column($history, 'status'));
        self::assertSame(['cancel'], $history[0]['actions']);
        self::assertSame(['retry', 'discard'], $history[1]['actions']);
        self::assertSame(['open_proposal', 'discard'], $history[2]['actions']);
        self::assertSame('Headline', $history[2]['name']);
        self::assertSame('design.png', $history[1]['name']);
        self::assertSame([$ready], array_column($this->scans('/project/' . self::PROJECT . '/ai-scans?filter=waiting'), 'id'));
        self::assertSame([$failed['id']], array_column($this->scans('/project/' . self::PROJECT . '/ai-scans?filter=failed'), 'id'));

        // The shell: one scan in progress, two things that want attention.
        $this->client->request('GET', '/ai-scans/mine');
        /** @var array{badge: array{inProgress: int, attention: int}, scans: list<Row>} $mine */
        $mine = self::decoded((string) $this->client->getResponse()->getContent());
        self::assertSame(['inProgress' => 1, 'attention' => 2], $mine['badge']);
        self::assertCount(3, $mine['scans']);

        $this->post(sprintf('/ai-scan/%s/seen', $ready));
        $this->client->request('GET', '/ai-scans/mine');
        /** @var array{badge: array{inProgress: int, attention: int}} $after */
        $after = self::decoded((string) $this->client->getResponse()->getContent());
        self::assertSame(['inProgress' => 1, 'attention' => 1], $after['badge']);

        // The entry card: enabled, credits, the queue line of a new scan.
        $entry = $this->entry();
        self::assertSame('enabled', $entry['state']);
        self::assertSame(['limit' => 10, 'used' => 2, 'left' => 8], $entry['credits'], 'the failed scan was refunded');
        self::assertSame(3, $entry['historyCount']);
        self::assertSame(1, $entry['queue']['position']);
        self::assertSame(2, $entry['fonts']['faces']);

        // Somebody else's failure leaves the strip after 24 hours; the requester keeps it.
        $this->backdate($failed['id'], 'finished_at', '-2 days');
        self::assertContains($failed['id'], array_column($this->scans('/project/' . self::PROJECT . '/ai-scans?view=strip'), 'id'));

        TestingLogin::logInAsUser($this->client, TestDataFixture::ADMIN_USER_EMAIL);
        $strip = $this->scans('/project/' . self::PROJECT . '/ai-scans?view=strip');
        self::assertNotContains($failed['id'], array_column($strip, 'id'));
        self::assertContains($ready, array_column($strip, 'id'));
        self::assertFalse($strip[0]['mine']);

        // After accept the template carries the pill for a week.
        $this->post(sprintf('/ai-scan/%s/accept', $ready));
        $this->client->request('GET', '/project/' . self::PROJECT . '/ai-scans');
        /** @var array{templates: array<string, string>} $listed */
        $listed = self::decoded((string) $this->client->getResponse()->getContent());
        self::assertSame([$ready], array_values($listed['templates']));
    }

    // ---- helpers -----------------------------------------------------------

    private function backdate(string $scanId, string $column, string $shift): void
    {
        self::connection()->executeStatement(
            sprintf("UPDATE ai_scan SET %s = NOW() + INTERVAL '%s' WHERE id = :id", $column, str_replace(['+', '-'], ['', '-'], $shift)),
            ['id' => $scanId],
        );
    }

    /**
     * @return array{state: string, requestSentAt: null|string, credits: array<string, null|int>, historyCount: int, queue: array{position: null|int}, fonts: array{faces: int}}
     */
    private function entry(): array
    {
        $this->client->request('GET', sprintf('/project/%s/ai-scan/entry', self::PROJECT));
        self::assertResponseIsSuccessful();

        /** @var array{entry: array{state: string, requestSentAt: null|string, credits: array<string, null|int>, historyCount: int, queue: array{position: null|int}, fonts: array{faces: int}}} $answer */
        $answer = self::decoded((string) $this->client->getResponse()->getContent());

        return $answer['entry'];
    }

    /**
     * @return list<Row>
     */
    private function scans(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        /** @var array{scans: list<Row>} $answer */
        $answer = self::decoded((string) $this->client->getResponse()->getContent());

        return $answer['scans'];
    }

    /**
     * @param array<string, string> $fields
     * @return array{id: string, created: bool, email: string, code: string}
     */
    private function post(string $url, array $fields = []): array
    {
        $this->client->request('POST', $url, ['_token' => 'csrf-token'] + $fields, server: ['HTTP_ORIGIN' => 'http://localhost']);

        /** @var array{id: string, created: bool, email: string, code: string} $answer */
        $answer = self::decoded((string) $this->client->getResponse()->getContent());

        return $answer;
    }
}
