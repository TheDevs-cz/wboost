<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\FaultInjection;

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use PHPUnit\Framework\Attributes\Group;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use WBoost\Web\Message\AiScan\AcceptCalendarScan;
use WBoost\Web\Message\AiScan\DiscardAiScan;
use WBoost\Web\Message\AiScan\RestoreCalendarScan;
use WBoost\Web\Message\Calendar\SaveCalendar;
use WBoost\Web\Services\Calendar\DescribeCalendarChange;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\AiScan\AiScanRefused;
use WBoost\Web\Value\Calendar\CalendarActor;
use WBoost\Web\Value\Calendar\CalendarContentOp;

/**
 * Fault injection for calendar scans (P5): the REAL `AcceptCalendarScan`,
 * `RestoreCalendarScan`, `DiscardAiScan` and a client's `SaveCalendar`
 * racing for one calendar and one proposal — each racer a process of its
 * own with its own connection ({@see RacesProcesses}).
 *
 * What must hold whoever wins: the layout is applied at most once per scan
 * (one revision, one history line, one snapshot); a discarded proposal is
 * never applied; a client's content saved at the same moment is never lost
 * and never blocks the accept; two restores put the layout back once.
 *
 *     docker compose exec web vendor/bin/phpunit --group fault-injection
 */
#[Group('fault-injection')]
final class CalendarScanRaceTest extends WebTestCase
{
    use AiScanTesting;
    use RacesProcesses;

    private const int ROUNDS = 4;
    private const string PROJECT = TestDataFixture::PROJECT_1_ID;
    private const string CALENDAR = TestDataFixture::CALENDAR_2_ID;

    private KernelBrowser $client;

    /** @var array<string, mixed> the calendar row as the fixtures made it */
    private array $calendarBefore = [];

    /** @var list<string> the calendar's history lines the fixtures made */
    private array $historyBefore = [];

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is not available.');
        }

        // Out of DAMA's transaction: racers must see each other's commits.
        StaticDriver::setKeepStaticConnections(false);
    }

    public static function tearDownAfterClass(): void
    {
        self::printTally();
        StaticDriver::setKeepStaticConnections(true);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $row = self::connection()->fetchAssociative('SELECT * FROM calendar WHERE id = :id', ['id' => self::CALENDAR]);
        self::assertIsArray($row);
        $this->calendarBefore = $row;
        /** @var list<string> $history */
        $history = self::connection()->fetchFirstColumn('SELECT id FROM calendar_history WHERE calendar_id = :id', ['id' => self::CALENDAR]);
        $this->historyBefore = $history;
        $this->clean();
        self::switchOn();
        self::entitle(self::PROJECT, true, null);
        self::connection()->executeStatement('UPDATE ai_scan_runtime SET daily_starts_user = 1000, daily_starts_project = 1000, daily_ceiling_micro_usd = 1000000000, project_daily_micro_usd = 1000000000');
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    public function testThreeAcceptsApplyTheLayoutOnce(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $id = self::calendarReady($this->client, self::CALENDAR);
            $revision = $this->revision();

            $results = $this->race(self::accept($id), self::accept($id), self::accept($id));

            $created = array_values(array_filter($results, static fn (mixed $result): bool => is_array($result) && ($result['created'] ?? null) === true));
            self::assertCount(1, $created, 'one of them applied the layout: ' . json_encode($results));
            self::assertCount(3, array_filter($results, is_array(...)), 'the others were answered with the first result: ' . json_encode($results));

            self::assertSame('accepted', self::text($id, 'status'));
            self::assertSame($revision + 1, $this->revision(), 'one save');
            self::assertSame(1, $this->sentences(DescribeCalendarChange::SCAN_APPLIED));
            self::assertSame('scan', $this->calendar('layout_origin'));
            self::assertNotSame('', self::text($id, 'restore'));
            self::assertSame(['1:reserve:-1', '1:settle:0'], self::ledger($id));
            $this->forget();
        }
    }

    public function testAnAcceptAgainstADiscardAppliesTheLayoutOrLeavesTheCalendarAlone(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $id = self::calendarReady($this->client, self::CALENDAR);
            $revision = $this->revision();

            [$accepted, $discarded] = $this->race(
                self::accept($id),
                static fn (ContainerInterface $c): string => self::dispatched($c, new DiscardAiScan(Uuid::fromString($id), Uuid::fromString(TestDataFixture::USER_1_ID))) ?? 'discarded',
            );

            self::won('calendar accept vs discard', is_array($accepted) ? 'accept' : 'discard');

            if (is_array($accepted)) {
                self::assertSame('wrong_state', $discarded);
                self::assertSame('accepted', self::text($id, 'status'));
                self::assertSame($revision + 1, $this->revision());
                self::assertSame('scan', $this->calendar('layout_origin'));
            } else {
                self::assertSame('wrong_state', $accepted);
                self::assertSame('discarded', self::text($id, 'status'));
                self::assertSame($revision, $this->revision(), 'a discarded proposal is never applied');
                self::assertSame('', $this->calendar('layout_origin'));
                self::assertSame(0, $this->sentences(DescribeCalendarChange::SCAN_APPLIED));
            }

            $this->forget();
        }
    }

    public function testAnAcceptAndAClientsSaveBothHappenAndTheClientsContentStays(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $id = self::calendarReady($this->client, self::CALENDAR);
            $revision = $this->revision();
            $caption = 'Souběh ' . $round;

            [$accepted, $saved] = $this->race(
                self::accept($id),
                static fn (ContainerInterface $c): string => self::dispatched($c, new SaveCalendar(
                    Uuid::fromString(self::CALENDAR),
                    CalendarActor::client(),
                    ops: [new CalendarContentOp('pageCaptions', 'w09', ['text' => $caption], '9. týden')],
                )) ?? 'saved',
            );

            self::assertIsArray($accepted, 'the accept is not blocked by a client: ' . json_encode($accepted));
            self::assertSame('saved', $saved);
            self::assertSame($revision + 2, $this->revision(), 'both saves happened, one after the other');
            self::assertSame('scan', $this->calendar('layout_origin'));

            $content = self::decoded($this->calendar('content'));
            self::assertSame($caption, self::dig($content, 'pageCaptions', 'w09', 'text'), 'the client\'s caption survived the accept');
            $layout = self::decoded($this->calendar('layout'));
            self::assertSame('start', self::dig($layout, 'styles', 'cal', 'numberSide'), 'the scanned layout is in');
            $this->forget();
        }
    }

    public function testTwoRestoresPutTheLayoutBackOnce(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $id = self::calendarReady($this->client, self::CALENDAR);
            self::assertIsArray($this->race(self::accept($id))[0]);
            $revision = $this->revision();

            $results = $this->race(self::restore($id), self::restore($id));

            $flags = array_map(static fn (mixed $result): mixed => is_array($result) ? $result['restored'] : $result, $results);
            sort($flags);
            self::assertSame([false, true], $flags, 'one restored, the other was told it had been: ' . json_encode($results));

            self::assertSame($revision + 1, $this->revision(), 'one save');
            self::assertSame(1, $this->sentences(DescribeCalendarChange::SCAN_RESTORED));
            self::assertSame('', $this->calendar('layout_origin'));
            self::assertEquals(self::decoded($this->calendarBefore['layout']), self::decoded($this->calendar('layout')));
            $this->forget();
        }
    }

    // ---- racers ------------------------------------------------------------

    /**
     * @return \Closure(ContainerInterface): mixed the handler's answer, or the refusal's code
     */
    private static function accept(string $id): \Closure
    {
        return static fn (ContainerInterface $c): mixed => self::answered($c, new AcceptCalendarScan(Uuid::fromString($id), Uuid::fromString(TestDataFixture::USER_1_ID), ['qr']));
    }

    /**
     * @return \Closure(ContainerInterface): mixed
     */
    private static function restore(string $id): \Closure
    {
        return static fn (ContainerInterface $c): mixed => self::answered($c, new RestoreCalendarScan(Uuid::fromString($id), Uuid::fromString(TestDataFixture::USER_1_ID)));
    }

    private static function answered(ContainerInterface $container, object $message): mixed
    {
        try {
            return $container->get(MessageBusInterface::class)->dispatch($message)->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failed) {
            $refused = $failed->getPrevious();

            if ($refused instanceof AiScanRefused) {
                return $refused->refusal->value;
            }

            throw $failed;
        }
    }

    /** Null when the message was handled, else the refusal's code. */
    private static function dispatched(ContainerInterface $container, object $message): null|string
    {
        $answer = self::answered($container, $message);

        return is_string($answer) ? $answer : null;
    }

    // ---- set-up ------------------------------------------------------------

    private function revision(): int
    {
        return (int) $this->calendar('revision');
    }

    private function calendar(string $column): string
    {
        $value = self::connection()->fetchOne(sprintf('SELECT %s FROM calendar WHERE id = :id', $column), ['id' => self::CALENDAR]);

        return is_scalar($value) ? (string) $value : '';
    }

    private function sentences(string $description): int
    {
        $count = self::connection()->fetchOne(
            'SELECT COUNT(*) FROM calendar_history WHERE calendar_id = :id AND description = :description',
            ['id' => self::CALENDAR, 'description' => $description],
        );

        return is_numeric($count) ? (int) $count : -1;
    }

    /** Between rounds: the scans, and the calendar as the fixtures made it. */
    private function forget(): void
    {
        $this->clean(false);
    }

    /**
     * Everything this suite commits, removed: scans, their ledger and usage,
     * and what an accept wrote into the calendar.
     */
    private function clean(bool $all = true): void
    {
        $connection = self::connection();
        $before = $this->calendarBefore;

        $connection->executeStatement('DELETE FROM ai_scan');
        $connection->executeStatement('DELETE FROM ai_credit_ledger');
        $connection->executeStatement('DELETE FROM ai_usage_event');
        $connection->executeStatement('DELETE FROM messenger_messages');
        // Every history line a racer committed (the accept's, the restore's, the client's caption).
        /** @var list<string> $history */
        $history = $connection->fetchFirstColumn('SELECT id FROM calendar_history WHERE calendar_id = :id', ['id' => self::CALENDAR]);

        foreach (array_diff($history, $this->historyBefore) as $entryId) {
            $connection->executeStatement('DELETE FROM calendar_history WHERE id = :id', ['id' => $entryId]);
        }

        $connection->executeStatement('DELETE FROM calendar_asset WHERE calendar_id = :id', ['id' => self::CALENDAR]);
        $connection->executeStatement(
            'UPDATE calendar SET layout = CAST(:layout AS JSONB), layout_preset = :preset, layout_origin = NULL, features = CAST(:features AS JSONB),
                    content = CAST(:content AS JSONB), revision = :revision, step = :step, updated_at = :updated
              WHERE id = :id',
            [
                'id' => self::CALENDAR, 'layout' => $before['layout'], 'preset' => $before['layout_preset'], 'features' => $before['features'],
                'content' => $before['content'], 'revision' => $before['revision'], 'step' => $before['step'], 'updated' => $before['updated_at'],
            ],
        );
        $connection->executeStatement("UPDATE ai_scan_runtime SET max_running = 1, consecutive_failures = 0, failure_class = NULL, provider_state = 'ok'");

        if ($all) {
            $connection->executeStatement('DELETE FROM ai_scan_runtime');
            $connection->executeStatement('UPDATE project SET ai_scan_enabled = false, ai_scan_monthly_limit = 10, ai_scan_enabled_at = NULL, ai_scan_enabled_by_id = NULL');
            $connection->executeStatement('UPDATE "user" SET ai_scan_consent_version = NULL, ai_scan_consent_at = NULL');
        }

        (new \Symfony\Component\Filesystem\Filesystem())->remove('/tmp/wboost-test-private/ai-scan');
    }
}
