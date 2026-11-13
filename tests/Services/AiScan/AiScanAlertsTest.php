<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\AiScan;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use WBoost\Web\Message\AiScan\SetAiScanRuntime;
use Twig\Environment;
use WBoost\Web\Services\AiScan\AiScanAlerts;
use WBoost\Web\Services\AiScan\AiScanConditions;
use WBoost\Web\Services\AiScan\AiScanRuntimeState;
use WBoost\Web\Services\AiScan\AiScanTime;
use WBoost\Web\Services\AiScan\AiScanTransitions;
use WBoost\Web\Services\AiScan\AiScanUrls;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\AiScan\AiScanAlertCode;
use WBoost\Web\Value\AiScan\AiScanMode;
use WBoost\Web\Value\AiScan\ProviderState;

/**
 * Operating e-mails to admins (`AiScanAlerts`, `app:ai-scan:watch`): every
 * condition opens once, does not repeat inside its limit, reminds once a
 * day, closes with one mail; nothing while the mode is `off`; a claim is won
 * once. And the mail about a project paused for refusals: once per project
 * and day, no reminder, no end.
 */
final class AiScanAlertsTest extends KernelTestCase
{
    use AiScanTesting;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();
        self::entitle(TestDataFixture::PROJECT_1_ID);
        self::switchOn();
        $this->now = new DateTimeImmutable();
    }

    public function testNothingIsSentWhileEverythingWorks(): void
    {
        self::assertSame(['lines' => [], 'failed' => 0], $this->watch());
        self::assertSame(['lines' => [], 'failed' => 0], $this->watch('+2 minutes'));
        self::assertEmailCount(0);
    }

    public function testScannerHeartbeatLostOpensOnceRemindsOnceADayAndClosesWithOneMail(): void
    {
        $this->scannerSeen('-10 minutes');

        self::assertSame(['opened scanner_absent'], $this->watch()['lines']);
        $mail = $this->lastMail();
        self::assertSame('AI sken: skener neodpovídá', $mail->getSubject());
        self::assertNotEmpty($mail->getTo(), 'the admins\' notification list (SIGNUP_NOTIFICATION_EMAILS)');
        $html = (string) $mail->getHtmlBody();
        self::assertStringContainsString('/admin/usage#ai-skeny', $html, 'a link to the admin tab');
        self::assertStringContainsString('Co udělat:', $html);
        self::assertStringContainsString('scanner_absent', $html);
        self::assertStringContainsString($this->now->modify('-10 minutes')->setTimezone(new \DateTimeZone('Europe/Prague'))->format('j. n. Y H:i'), $html, 'since when');

        // Not again while it lasts …
        self::assertSame([], $this->watch('+5 minutes')['lines']);
        self::assertSame([], $this->watch('+23 hours')['lines']);
        self::assertEmailCount(1);

        // … except one reminder a day.
        self::assertSame(['reminded scanner_absent'], $this->watch('+25 hours')['lines']);
        self::assertSame('AI sken: skener neodpovídá (stále trvá)', $this->lastMail()->getSubject());
        self::assertSame([], $this->watch('+26 hours')['lines']);
        self::assertEmailCount(2);

        // The scanner calls again: one mail, once.
        $this->scannerSeen('+27 hours');
        self::assertSame(['cleared scanner_absent'], $this->watch('+27 hours')['lines']);
        self::assertSame('AI sken: skener znovu odpovídá', $this->lastMail()->getSubject());
        self::assertSame([], $this->watch('+27 hours +1 minute')['lines']);
        self::assertEmailCount(3);
    }

    public function testAConditionThatComesBackAtOnceIsNotAnnouncedAgainRightAway(): void
    {
        $this->scannerSeen('-10 minutes');
        self::assertSame(['opened scanner_absent'], $this->watch()['lines']);

        $this->scannerSeen('+5 minutes');
        self::assertSame(['cleared scanner_absent'], $this->watch('+5 minutes')['lines']);

        // Away again ten minutes later: inside the quiet half hour.
        self::assertSame([], $this->watch('+15 minutes')['lines']);
        self::assertSame(['opened scanner_absent'], $this->watch('+40 minutes')['lines']);
        self::assertEmailCount(3);
    }

    public function testNothingIsSentWhileTheModeIsOffExceptTheEndOfAnAnnouncedCondition(): void
    {
        self::bus()->dispatch(new SetAiScanRuntime(mode: AiScanMode::Off));
        $this->scannerSeen('-10 minutes');
        self::runtime()->providerReported(ProviderState::Blocked, 'billing_error');

        self::assertSame([], $this->watch()['lines']);
        self::assertSame([], $this->watch('+30 minutes')['lines']);
        self::assertEmailCount(0);

        // Announced while on …
        self::bus()->dispatch(new SetAiScanRuntime(mode: AiScanMode::On));
        self::assertSame(['opened scanner_absent', 'opened provider_blocked'], $this->watch('+31 minutes')['lines']);

        // … then switched off: no reminder, however long it lasts …
        self::bus()->dispatch(new SetAiScanRuntime(mode: AiScanMode::Off));
        self::assertSame([], $this->watch('+30 hours')['lines']);
        self::assertEmailCount(2);

        // … but its end is told.
        $this->scannerSeen('+31 hours');
        self::assertSame(['cleared scanner_absent'], $this->watch('+31 hours')['lines']);
        self::assertStringContainsString('Vypnuto', (string) $this->lastMail()->getHtmlBody());
        self::assertEmailCount(3);
    }

    public function testProviderBlockedIsAnnouncedAfterTenMinutesAndCleared(): void
    {
        self::runtime()->providerReported(ProviderState::Blocked, 'billing_error');

        self::assertSame([], $this->watch()['lines'], 'not yet: it may be a blip');
        $this->scannerSeen('+6 minutes');
        self::assertSame([], $this->watch('+6 minutes')['lines']);
        $this->scannerSeen('+11 minutes');
        self::assertSame(['opened provider_blocked'], $this->watch('+11 minutes')['lines']);
        self::assertSame('AI sken: poskytovatel odmítá náš účet', $this->lastMail()->getSubject());
        self::assertStringContainsString('billing_error', (string) $this->lastMail()->getHtmlBody());

        $this->scannerSeen('+16 minutes');
        self::assertSame([], $this->watch('+16 minutes')['lines']);

        self::runtime()->providerReported(ProviderState::Ok, null);
        self::assertSame(['cleared provider_blocked'], $this->watch('+17 minutes')['lines']);
        self::assertSame('AI sken: poskytovatel náš účet znovu přijímá', $this->lastMail()->getSubject());
        self::assertEmailCount(2);
    }

    public function testAProviderBlipSendsNothing(): void
    {
        self::runtime()->providerReported(ProviderState::Blocked, 'billing_error');
        self::assertSame([], $this->watch()['lines']);

        self::runtime()->providerReported(ProviderState::Ok, null);
        $this->scannerSeen('+5 minutes');
        self::assertSame([], $this->watch('+5 minutes')['lines']);

        // Blocked again later: the ten minutes count from the new start.
        self::runtime()->providerReported(ProviderState::Blocked, 'billing_error');
        $this->scannerSeen('+12 minutes');
        self::assertSame([], $this->watch('+12 minutes')['lines']);
        self::assertEmailCount(0);
    }

    public function testTheCircuitBreakerIsAnnouncedButAPauseSetByHandIsNot(): void
    {
        self::bus()->dispatch(new SetAiScanRuntime(mode: AiScanMode::Paused));
        self::assertSame([], $this->watch()['lines'], 'the admin paused it: nobody needs a mail');
        self::bus()->dispatch(new SetAiScanRuntime(mode: AiScanMode::On));

        self::assertFalse(self::runtime()->scanEnded('build'));
        self::assertFalse(self::runtime()->scanEnded('build'));
        self::assertTrue(self::runtime()->scanEnded('build'));
        self::assertSame('paused', self::runtimeValue('mode'));
        self::assertNotNull(self::runtimeValue('breaker_tripped_at'));

        self::assertSame(['opened breaker_tripped'], $this->watch()['lines']);
        self::assertSame('AI sken: pozastaveno po třech chybách za sebou', $this->lastMail()->getSubject());
        self::assertStringContainsString('(build)', (string) $this->lastMail()->getHtmlBody());
        self::assertSame([], $this->watch('+1 minute')['lines']);

        // A scan that ends well while paused resets the counter — the pause is still the breaker's.
        self::runtime()->scanEnded(null);
        self::assertSame([], $this->watch('+2 minutes')['lines']);

        self::bus()->dispatch(new SetAiScanRuntime(mode: AiScanMode::On));
        self::assertNull(self::runtimeValue('breaker_tripped_at'));
        self::assertSame(['cleared breaker_tripped'], $this->watch('+2 minutes')['lines']);
        self::assertStringContainsString('Teď je: Zapnuto.', (string) $this->lastMail()->getHtmlBody());
        self::assertEmailCount(2);
    }

    public function testSpendAtEightyPercentAndAtTheCeiling(): void
    {
        // Ceiling 20 $: 16.50 $ is over 80 % and one more run (1.50 $) still fits.
        $this->spend(16_500_000);
        self::assertSame(['opened spend_80'], $this->watch()['lines']);
        self::assertSame('AI sken: dnešní útrata je na 80 % denního stropu', $this->lastMail()->getSubject());
        self::assertStringContainsString('16,50 $', (string) $this->lastMail()->getHtmlBody());
        self::assertSame([], $this->watch('+20 seconds')['lines']);

        // 19 $: nothing more fits. The 80 % mark ends without a mail of its own.
        $this->spend(2_500_000);
        self::assertSame(['opened spend_ceiling'], $this->watch('+40 seconds')['lines']);
        self::assertSame('AI sken: denní strop útraty je dosažen', $this->lastMail()->getSubject());
        self::assertSame([], $this->watch('+60 seconds')['lines']);
        self::assertEmailCount(2);

        // The ceiling is raised: one "back to normal".
        self::bus()->dispatch(new SetAiScanRuntime(dailyCeilingMicroUsd: 100_000_000));
        self::assertSame(['cleared spend_ceiling'], $this->watch('+80 seconds')['lines']);
        self::assertSame('AI sken: denní strop už skeny neblokuje', $this->lastMail()->getSubject());
        self::assertSame([], $this->watch('+100 seconds')['lines']);
        self::assertEmailCount(3);
    }

    public function testAScanWaitingLongerThanFifteenMinutes(): void
    {
        $scanId = self::start()->toString();
        self::assertSame([], $this->watch()['lines']);

        self::connection()->executeStatement(
            'UPDATE ai_scan SET waiting_since = :at WHERE id = :id',
            ['id' => $scanId, 'at' => AiScanTime::sql($this->now->modify('-20 minutes'))],
        );
        self::assertSame(['opened queue_stalled'], $this->watch()['lines']);
        self::assertSame('AI sken: skeny čekají déle než 15 minut', $this->lastMail()->getSubject());
        $html = (string) $this->lastMail()->getHtmlBody();
        self::assertStringContainsString('čekajících skenů je 1', $html);
        self::assertStringNotContainsString('design.png', $html, 'numbers only');
        self::assertSame([], $this->watch('+1 minute')['lines']);

        self::assertTrue(self::getContainer()->get(AiScanTransitions::class)->cancel($scanId));
        self::assertSame(['cleared queue_stalled'], $this->watch('+2 minutes')['lines']);
        self::assertEmailCount(2);
    }

    public function testAScannerThatIsTooOld(): void
    {
        self::runtime()->scannerTooOld('0.1');
        $this->scannerSeen('+0 seconds');

        self::assertSame(['opened scanner_too_old'], $this->watch()['lines']);
        self::assertStringContainsString('(verze 0.1)', (string) $this->lastMail()->getHtmlBody());
        self::assertSame([], $this->watch('+1 minute')['lines']);

        self::runtime()->scannerSeen('test-2');
        $this->scannerSeen('+2 minutes');
        self::assertSame(['cleared scanner_too_old'], $this->watch('+2 minutes')['lines']);
        self::assertEmailCount(2);
    }

    /**
     * Two processes that read the same row: the conditional update lets one
     * of them send. (One connection under DAMA — the second claim is made
     * with what the first one read.)
     */
    public function testAClaimIsWonOnce(): void
    {
        $alerts = self::getContainer()->get(AiScanAlerts::class);
        $this->watch();
        $code = AiScanAlertCode::ScannerAbsent;
        $opened = $this->now;

        self::assertTrue($alerts->claimOpen($code, $opened->modify('-5 minutes'), $opened));
        self::assertFalse($alerts->claimOpen($code, $opened->modify('-5 minutes'), $opened));

        $later = $opened->modify('+25 hours');
        self::assertTrue($alerts->claimReminder($code, $opened, $later));
        self::assertFalse($alerts->claimReminder($code, $opened, $later));

        self::assertTrue($alerts->claimClose($code, $opened, $later->modify('+1 hour')));
        self::assertFalse($alerts->claimClose($code, $opened, $later->modify('+1 hour')));
        self::assertSame([], $alerts->open());
    }

    public function testAProjectPausedForRefusalsIsAnnouncedOncePerProjectAndDay(): void
    {
        // Noon in Prague, so that "an hour later" is the same day whenever the suite runs.
        $this->now = AiScanTime::startOfDay($this->now)->modify('+12 hours');
        $this->scannerSeen('+0 seconds');
        $project = TestDataFixture::PROJECT_1_ID;
        $name = self::connection()->fetchOne('SELECT name FROM project WHERE id = :id', ['id' => $project]);
        self::assertIsString($name);

        $this->refusal('-2 hours');
        $this->refusal('-1 hour');
        $this->refusal('-1 day', 'yesterday does not count');
        self::assertSame([], $this->watch()['lines'], 'two refusals today pause nothing');

        $this->refusal('-1 minute');
        self::assertSame(['opened project_paused ' . $project], $this->watch()['lines']);
        self::assertEmailCount(1);

        $mail = $this->lastMail();
        self::assertSame(sprintf('AI sken: projekt %s je do zítřka pozastaven', $name), $mail->getSubject());
        self::assertNotEmpty($mail->getTo());
        $html = (string) $mail->getHtmlBody();
        self::assertStringContainsString(sprintf('odmítl 3 obrázky z projektu %s', htmlspecialchars($name)), $html);
        self::assertStringContainsString($project, $html, 'the project id');
        self::assertStringContainsString('project_paused', $html);
        self::assertStringContainsString($this->now->format('j. n. Y'), $html, 'the day');
        self::assertStringContainsString($this->now->modify('+1 day')->format('j. n. Y') . ' 00:00', $html, 'when starts resume');
        self::assertStringContainsString(sprintf('/admin/usage?ai_project=%s#ai-skeny', $project), $html, 'a button to the project\'s scans');
        self::assertStringContainsString('Co udělat:', $html);
        self::assertStringContainsString('další zprávu o něm nepošleme', $html);
        self::assertStringNotContainsString('O konci tohoto stavu', $html, 'it ends by itself, silently');
        self::assertStringNotContainsString('design.png', $html);
        self::assertStringNotContainsString('user@example.com', $html, 'no address, no picture');

        // Not again the same day — however many refusals follow, and no reminder.
        $this->refusal('+10 minutes');
        $this->scannerSeen('+1 hour');
        self::assertSame([], $this->watch('+1 hour')['lines']);
        $this->scannerSeen('+11 hours');
        self::assertSame([], $this->watch('+11 hours')['lines']);

        // Midnight: the pause is over, and nobody is told.
        $this->scannerSeen('+13 hours');
        self::assertSame([], $this->watch('+13 hours')['lines']);
        self::assertEmailCount(1);

        // Three more the next day: announced again.
        $this->refusal('+20 hours');
        $this->refusal('+21 hours');
        $this->scannerSeen('+22 hours');
        self::assertSame([], $this->watch('+22 hours')['lines']);
        $this->refusal('+22 hours');
        self::assertSame(['opened project_paused ' . $project], $this->watch('+22 hours')['lines']);
        self::assertSame([], $this->watch('+22 hours +2 minutes')['lines']);
        self::assertEmailCount(2);
        self::assertSame(2, self::rowCount('ai_scan_project_pause', sprintf("project_id = '%s'", $project)));
    }

    public function testAPausedProjectIsNotAnnouncedWhileTheModeIsOffButIsOnceItIsBackTheSameDay(): void
    {
        $this->now = AiScanTime::startOfDay($this->now)->modify('+12 hours');
        $this->scannerSeen('+0 seconds');
        $this->refusal('-3 minutes');
        $this->refusal('-2 minutes');
        $this->refusal('-1 minute');

        self::bus()->dispatch(new SetAiScanRuntime(mode: AiScanMode::Off));
        self::assertSame([], $this->watch()['lines']);
        self::assertEmailCount(0);

        self::bus()->dispatch(new SetAiScanRuntime(mode: AiScanMode::On));
        $this->scannerSeen('+1 hour');
        self::assertSame(['opened project_paused ' . TestDataFixture::PROJECT_1_ID], $this->watch('+1 hour')['lines']);
        self::assertEmailCount(1);
    }

    public function testThePausedProjectClaimIsWonOncePerProjectAndDay(): void
    {
        $alerts = self::getContainer()->get(AiScanAlerts::class);
        $day = AiScanTime::startOfDay($this->now);

        self::assertTrue($alerts->claimProjectPause(TestDataFixture::PROJECT_1_ID, $day, 3, $this->now));
        self::assertFalse($alerts->claimProjectPause(TestDataFixture::PROJECT_1_ID, $day, 4, $this->now));
        self::assertTrue($alerts->claimProjectPause(TestDataFixture::PROJECT_2_ID, $day, 3, $this->now), 'another project');
        self::assertTrue($alerts->claimProjectPause(TestDataFixture::PROJECT_1_ID, $day->modify('+1 day'), 3, $this->now), 'another day');

        // The row that lost changed nothing; and a claimed day sends no mail from the watch.
        self::assertEquals(3, self::connection()->fetchOne(
            'SELECT refusals FROM ai_scan_project_pause WHERE project_id = :id AND day = :day',
            ['id' => TestDataFixture::PROJECT_1_ID, 'day' => $day->format('Y-m-d')],
        ));
        $this->now = $day->modify('+12 hours');
        $this->scannerSeen('+0 seconds');
        $this->refusal('-3 minutes');
        $this->refusal('-2 minutes');
        $this->refusal('-1 minute');
        self::assertSame([], $this->watch()['lines']);
        self::assertEmailCount(0);
    }

    public function testNothingIsEvaluatedWithoutRecipients(): void
    {
        $this->now = AiScanTime::startOfDay($this->now)->modify('+12 hours');
        $this->scannerSeen('-10 minutes');
        $this->refusal('-3 minutes');
        $this->refusal('-2 minutes');
        $this->refusal('-1 minute');

        $container = self::getContainer();
        $alerts = new AiScanAlerts(
            self::connection(),
            $container->get(ClockInterface::class),
            self::runtime(),
            $container->get(AiScanConditions::class),
            $container->get(MailerInterface::class),
            $container->get(Environment::class),
            $container->get(AiScanUrls::class),
            [],
        );

        self::assertSame(['lines' => [], 'failed' => 0], $alerts->run($this->now));
        self::assertEmailCount(0);
        self::assertSame(0, self::rowCount('ai_scan_project_pause', 'TRUE'), 'no claim is burnt while nobody would read the mail');

        // With recipients the same state is announced.
        self::assertSame(['opened scanner_absent', 'opened project_paused ' . TestDataFixture::PROJECT_1_ID], $this->watch()['lines']);
    }

    public function testTheWatchCommand(): void
    {
        $kernel = self::$kernel;
        assert($kernel !== null);
        $watch = new CommandTester((new Application($kernel))->find('app:ai-scan:watch'));

        self::assertSame(0, $watch->execute([]));
        self::assertSame('', trim($watch->getDisplay()), 'nothing to do: no output');

        $this->scannerSeen('-10 minutes');
        self::assertSame(0, $watch->execute([]));
        self::assertSame('opened scanner_absent', trim($watch->getDisplay()));

        self::assertSame(0, $watch->execute([]));
        self::assertSame('', trim($watch->getDisplay()));
        self::assertEmailCount(1);
        self::assertArrayHasKey('scanner_absent', self::getContainer()->get(AiScanAlerts::class)->open());
    }

    /**
     * @return array{lines: list<string>, failed: int}
     */
    private function watch(string $shift = '+0 seconds'): array
    {
        return self::getContainer()->get(AiScanAlerts::class)->run($this->now->modify($shift));
    }

    private function scannerSeen(string $shift): void
    {
        self::connection()->executeStatement(
            'UPDATE ai_scan_runtime SET scanner_seen_at = :at WHERE id = 1',
            ['at' => AiScanTime::sql($this->now->modify($shift))],
        );
    }

    /** A closed usage row of today. */
    private function spend(int $microUsd): void
    {
        self::connection()->executeStatement(
            "INSERT INTO ai_usage_event (id, scan_id, run, step, occurred_at, closed_at, cost_micro_usd, outcome, project_id, project_name, owner_email, user_email, channel, kind)
             VALUES (:id, :scan, 1, 'builder', :at, :at, :cost, 'proposed', :project, 'Projekt', 'owner@example.com', 'user@example.com', 'web', 'template')",
            [
                'id' => Uuid::uuid7()->toString(),
                'scan' => Uuid::uuid7()->toString(),
                'at' => AiScanTime::sql($this->now),
                'cost' => $microUsd,
                'project' => TestDataFixture::PROJECT_1_ID,
            ],
        );
    }

    /** A closed usage row of the project that ended in a refusal by the provider. */
    private function refusal(string $shift, string $why = ''): void
    {
        self::connection()->executeStatement(
            "INSERT INTO ai_usage_event (id, scan_id, run, step, occurred_at, closed_at, cost_micro_usd, outcome, project_id, project_name, owner_email, user_email, channel, kind)
             VALUES (:id, :scan, 1, 'pre_check', :at, :at, 1000, 'refused', :project, 'Projekt', 'owner@example.com', 'user@example.com', 'web', 'template')",
            [
                'id' => Uuid::uuid7()->toString(),
                'scan' => Uuid::uuid7()->toString(),
                'at' => AiScanTime::sql($this->now->modify($shift)),
                'project' => TestDataFixture::PROJECT_1_ID,
            ],
        );
    }

    private function lastMail(): Email
    {
        $messages = self::getMailerMessages();
        $mail = end($messages);
        self::assertInstanceOf(Email::class, $mail);

        return $mail;
    }

    private static function runtime(): AiScanRuntimeState
    {
        return self::getContainer()->get(AiScanRuntimeState::class);
    }
}
