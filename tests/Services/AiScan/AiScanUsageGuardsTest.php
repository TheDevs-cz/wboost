<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\AiScan;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use WBoost\Web\Services\AiScan\AiScanAlerts;
use WBoost\Web\Services\AiScan\AiScanSpend;
use WBoost\Web\Services\AiScan\AiScanTime;
use WBoost\Web\Services\AiScan\RecordAiUsage;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\AiScan\AiScanLimits;

/**
 * What the scanner reports about its spending is another process's word:
 * one run's recorded cost is capped at a few engine budgets, and the admins
 * are told — once, then at most daily — about a model the price table does
 * not know, a finished build without a single token, and a capped run.
 */
final class AiScanUsageGuardsTest extends WebTestCase
{
    use AiScanTesting;

    /** A model of the definition's price table. */
    private const string PRICED = 'claude-opus-5-5';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::entitle(TestDataFixture::PROJECT_1_ID, true, null);
        self::switchOn();
    }

    public function testAnHonestRunIsRecordedAsReportedAndNobodyIsAlerted(): void
    {
        $id = $this->finishedWith(self::usage(2, self::PRICED), self::usage(1, self::PRICED));

        $row = $this->usageRow($id, 'builder');
        self::assertNotNull($row['price_version']);
        self::assertGreaterThan(0, $row['cost_micro_usd']);
        self::assertLessThan($this->cap(), $row['cost_micro_usd']);
        self::assertSame([], $this->watch());
        self::assertEmailCount(0);
    }

    public function testOneRunsCostIsCappedWhateverTheScannerSays(): void
    {
        self::assertSame(AiScanLimits::USAGE_COST_CAP_BUDGETS * 1_500_000, $this->cap());

        // A model the table does not know and the largest figure the payload admits: $2000.
        $build = self::running($this->client);
        $absurd = ['engineCostMicroUsd' => 2_000_000_000] + self::usage(1, 'made-up-model');
        self::scanner($this->client, 'POST', $build['id'] . '/beat', ['run' => $build['run'], 'seq' => 1, 'usage' => $absurd], $build['lease']);

        $row = $this->usageRow($build['id'], 'builder');
        self::assertSame($this->cap(), $row['cost_micro_usd'], 'recorded at the cap');
        self::assertSame(2_000_000_000, $row['engine_cost_micro_usd'], 'what was said stays readable');
        self::assertNull($row['price_version']);

        // A known model with absurd token counts is capped just the same.
        self::scanner($this->client, 'POST', $build['id'] . '/beat', ['run' => $build['run'], 'seq' => 2, 'usage' => ['outputTokens' => 2_000_000_000, 'engineCostMicroUsd' => 0] + self::usage(1, self::PRICED)], $build['lease']);
        $row = $this->usageRow($build['id'], 'builder');
        self::assertSame($this->cap(), $row['cost_micro_usd']);
        self::assertNotNull($row['price_version']);

        // So the day's ceiling ($20) is not closed by one report: the scan's total is the cap + its intake.
        self::assertSame($this->cap() + 100_000, self::number($build['id'], 'cost_micro_usd'));
        self::assertLessThan(20_000_000, self::getContainer()->get(AiScanSpend::class)->recordedToday(new \DateTimeImmutable()));

        self::assertSame(['opened usage_suspect'], $this->watch());
        $html = (string) $this->lastMail()->getHtmlBody();
        self::assertSame('AI sken: hlášená spotřeba neodpovídá ceníku', $this->lastMail()->getSubject());
        self::assertStringContainsString('usage_suspect', $html);
        self::assertStringContainsString('cena jednoho běhu nad stropem 3× rozpočtu — 1×', $html);
        self::assertStringContainsString('/admin/usage#ai-skeny', $html);
    }

    public function testAnUnknownModelAndAFinishedBuildWithoutTokensAreAnnouncedOnceAndRemindedDaily(): void
    {
        // The intake on a model the table does not know; the build finishes with no usage at all.
        $this->finishedWith([], self::usage(1, 'made-up-model'));

        self::assertSame(['opened usage_suspect'], $this->watch());
        self::assertEmailCount(1);
        $html = (string) $this->lastMail()->getHtmlBody();
        self::assertStringContainsString('neznámý model v ceníku — 1× (made-up-model)', $html);
        self::assertStringContainsString('hotový návrh bez jediného tokenu — 1×', $html);
        self::assertStringContainsString('Co udělat:', $html);

        // Rate-limited like every other condition: not again while it lasts…
        self::assertSame([], $this->watch('+5 minutes'));
        self::assertSame([], $this->watch('+12 hours'));
        self::assertEmailCount(1);

        // …one reminder a day while new suspect reports keep coming…
        self::connection()->executeStatement("UPDATE ai_usage_event SET occurred_at = occurred_at + INTERVAL '20 hours'");
        self::assertSame(['reminded usage_suspect'], $this->watch('+25 hours'));
        self::assertEmailCount(2);

        // …and it closes without a mail once a day passed without one.
        self::assertSame([], $this->watch('+3 days'));
        self::assertEmailCount(2);
        self::assertNull(self::connection()->fetchOne("SELECT opened_at FROM ai_scan_alert WHERE code = 'usage_suspect'"));
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * A scan that ends `ready`, with the given usage at the build's finish and at the intake's.
     *
     * @param array<string, mixed> $buildUsage
     * @param array<string, mixed> $intakeUsage
     */
    private function finishedWith(array $buildUsage, array $intakeUsage): string
    {
        $id = self::start()->toString();
        $intake = self::claim($this->client, 'intake');

        foreach (['working', 'preview'] as $which) {
            self::scanner($this->client, 'PUT', sprintf('%s/artifact/%s?run=%d', $id, $which, $intake['run']), 'WEBP-BYTES-' . $which, $intake['lease']);
        }

        self::scanner($this->client, 'POST', $id . '/finish', ['run' => $intake['run'], 'outcome' => 'passed', 'usage' => $intakeUsage], $intake['lease']);
        $build = self::claim($this->client, 'build');
        self::scanner($this->client, 'POST', $id . '/render', ['run' => $build['run'], 'seq' => 1, 'design' => self::design()], $build['lease']);
        self::scanner($this->client, 'POST', $id . '/finish', ['run' => $build['run'], 'outcome' => 'proposed', 'candidate' => 1, 'report' => self::report(1), 'usage' => $buildUsage], $build['lease']);
        self::assertSame('ready', self::text($id, 'status'));

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function usageRow(string $scanId, string $step): array
    {
        $row = self::connection()->fetchAssociative('SELECT * FROM ai_usage_event WHERE scan_id = :id AND step = :step ORDER BY run DESC LIMIT 1', ['id' => $scanId, 'step' => $step]);
        self::assertIsArray($row);

        return $row;
    }

    private function cap(): int
    {
        return self::getContainer()->get(RecordAiUsage::class)->costCapMicroUsd();
    }

    /**
     * @return list<string>
     */
    private function watch(string $shift = '+0 seconds'): array
    {
        $at = (new \DateTimeImmutable())->modify($shift);
        // The scanner is alive at that moment: this test is about usage only.
        self::connection()->executeStatement('UPDATE ai_scan_runtime SET scanner_seen_at = :at WHERE id = 1', ['at' => AiScanTime::sql($at)]);

        return self::getContainer()->get(AiScanAlerts::class)->run($at)['lines'];
    }

    private function lastMail(): Email
    {
        $messages = self::getMailerMessages();
        $mail = end($messages);
        self::assertInstanceOf(Email::class, $mail);

        return $mail;
    }
}
