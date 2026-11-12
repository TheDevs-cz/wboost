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
use WBoost\Web\Message\AiScan\AcceptTemplateScan;
use WBoost\Web\Message\AiScan\ApplyAiScanFix;
use WBoost\Web\Message\AiScan\DiscardAiScan;
use WBoost\Web\Services\AiScan\AiScanPurge;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\AiScan\AiScanRefused;

/**
 * Fault injection for the review (P3): the races around a `ready` proposal,
 * with the REAL messages on the real bus — `AcceptTemplateScan` (which
 * creates a template), `ApplyAiScanFix`, `DiscardAiScan` and the nightly
 * purge — each racer a process of its own with its own connection
 * ({@see RacesProcesses}).
 *
 * What must hold whoever wins: at most one template per scan; a proposal
 * that was discarded or expired never becomes a template; a fix that was
 * answered "saved" is in the template; no fix of one tab is lost to another.
 *
 *     docker compose exec web vendor/bin/phpunit --group fault-injection
 */
#[Group('fault-injection')]
final class AiScanReviewRaceTest extends WebTestCase
{
    use AiScanTesting;
    use RacesProcesses;

    private const int ROUNDS = 4;
    private const string PROJECT = TestDataFixture::PROJECT_1_ID;

    private KernelBrowser $client;

    /** @var list<string> */
    private array $templatesBefore = [];

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
        /** @var list<string> $templates */
        $templates = self::connection()->fetchFirstColumn('SELECT id FROM template');
        $this->templatesBefore = $templates;
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

    public function testTwoAcceptsCreateOneTemplate(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $id = $this->proposal();

            $results = $this->race(self::accept($id), self::accept($id), self::accept($id));

            $created = array_values(array_filter($results, static fn (mixed $result): bool => is_array($result) && ($result['created'] ?? null) === true));
            self::assertCount(1, $created, 'one of them created the template: ' . json_encode($results));

            $templateIds = array_values(array_unique(array_map(
                static fn (mixed $result): string => is_array($result) && is_string($result['templateId'] ?? null) ? $result['templateId'] : 'refused: ' . json_encode($result),
                $results,
            )));
            self::assertCount(1, $templateIds, 'every caller was given the same template: ' . json_encode($results));
            self::assertSame(self::text($id, 'result_template_id'), $templateIds[0]);

            self::assertSame('accepted', self::text($id, 'status'));
            self::assertSame(1, $this->newTemplates(), 'exactly one template exists for the scan');
            self::assertSame(1, self::rowCount('template_variant', "template_id = '" . self::text($id, 'result_template_id') . "'"));
            self::assertSame(['1:reserve:-1', '1:settle:0'], self::ledger($id));
            $this->forget();
        }
    }

    public function testAnAcceptAgainstADiscardLeavesATemplateOrNothing(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $id = $this->proposal();

            [$accepted, $discarded] = $this->race(
                self::accept($id),
                static fn (ContainerInterface $c): string => self::dispatched($c, new DiscardAiScan(Uuid::fromString($id), Uuid::fromString(TestDataFixture::USER_1_ID))) ?? 'discarded',
            );

            self::won('accept vs discard', is_array($accepted) ? 'accept' : 'discard');

            if (is_array($accepted)) {
                // The accept won: the discard was refused, the template is whole.
                self::assertSame('wrong_state', $discarded);
                self::assertSame('accepted', self::text($id, 'status'));
                self::assertSame(1, $this->newTemplates());
                self::assertNotSame('', self::text($id, 'proposal'), 'an accepted scan keeps its proposal');
            } else {
                // The discard won: no template, not even half of one.
                self::assertSame('wrong_state', $accepted);
                self::assertSame('discarded', $discarded);
                self::assertSame('discarded', self::text($id, 'status'));
                self::assertSame(0, $this->newTemplates());
                self::assertSame('', self::text($id, 'result_template_id'));
            }

            self::assertSame(['1:reserve:-1', '1:settle:0'], self::ledger($id), 'a delivered proposal stays counted either way');
            $this->forget();
        }
    }

    public function testAnAcceptAgainstTheNightlyExpiryLeavesATemplateOrNothing(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $id = $this->proposal();
            self::connection()->executeStatement("UPDATE ai_scan SET expires_at = NOW() - INTERVAL '1 hour' WHERE id = :id", ['id' => $id]);

            [$accepted, $expired] = $this->race(
                self::accept($id),
                self::headStart($round, static fn (ContainerInterface $c): int => $c->get(AiScanPurge::class)->run()['expired']),
            );

            self::won('accept vs expiry', is_array($accepted) ? 'accept' : 'expiry');

            if (is_array($accepted)) {
                self::assertSame(0, $expired);
                self::assertSame('accepted', self::text($id, 'status'));
                self::assertSame(1, $this->newTemplates());
                // The template does not depend on the scan's files: its canvas is its own.
                self::assertNotSame('', $this->canvasOf($id));
            } else {
                self::assertSame('wrong_state', $accepted);
                self::assertSame(1, $expired);
                self::assertSame('expired', self::text($id, 'status'));
                self::assertSame('', self::text($id, 'proposal'));
                self::assertSame(0, $this->newTemplates());
            }

            $this->forget();
        }
    }

    public function testAFixAgainstAnAcceptIsInTheTemplateOrWasRefused(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $id = $this->proposal();

            [$accepted, $fixed] = $this->race(
                self::accept($id),
                self::headStart($round, self::fix($id, ['type' => 'set_text', 'id' => 'headline', 'text' => 'Opraveno v druhé záložce'])),
            );

            self::assertIsArray($accepted, 'an accept is never refused because of a fix');
            self::assertSame('accepted', self::text($id, 'status'));
            self::assertSame(1, $this->newTemplates());

            $canvas = $this->canvasOf($id);
            $inTemplate = str_contains($canvas, json_encode('Opraveno v druhé záložce', JSON_THROW_ON_ERROR)) || str_contains($canvas, 'Opraveno v druhé záložce');

            self::won('fix vs accept', $fixed === 'saved' ? 'fix first' : 'accept first');

            if ($fixed === 'saved') {
                // The fix was answered "saved": it came before the accept, so the template carries it.
                self::assertTrue($inTemplate, 'a saved fix is in the template');
                self::assertStringContainsString('Opraveno', self::text($id, 'fixes'));
            } else {
                // The accept came first: the review is read-only, the fix is refused and nothing of it exists.
                self::assertSame('wrong_state', $fixed);
                self::assertFalse($inTemplate);
                self::assertStringNotContainsString('Opraveno', self::text($id, 'fixes'));
            }

            $this->forget();
        }
    }

    public function testTwoTabsApplyingFixesLoseNothing(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $id = $this->proposal();

            // Different items: both are saved, whatever the order.
            $results = $this->race(
                self::fix($id, ['type' => 'set_text', 'id' => 'headline', 'text' => 'Záložka A']),
                self::fix($id, ['type' => 'rename', 'id' => 'sub', 'name' => 'Záložka B']),
                self::fix($id, ['type' => 'keep_font', 'group' => 'headline']),
            );
            self::assertSame(['saved', 'saved', 'saved'], $results);

            $fixes = self::decoded(self::text($id, 'fixes'));
            self::assertSame(3, $fixes['rev'] ?? null, 'three saves, three revisions — none overwrote another');
            $items = self::decoded($fixes['items'] ?? null);
            self::assertSame('Záložka A', self::dig($items, 'text:headline', 'text'));
            self::assertSame('Záložka B', self::dig($items, 'name:sub', 'name') ?? self::dig($items, 'rename:sub', 'name'));
            self::assertCount(3, $items);

            // The same item from two tabs: the last write wins — one of the two, never a mix, never neither.
            $same = $this->race(
                self::fix($id, ['type' => 'set_text', 'id' => 'sub', 'text' => 'první']),
                self::fix($id, ['type' => 'set_text', 'id' => 'sub', 'text' => 'druhý']),
            );
            self::assertSame(['saved', 'saved'], $same);

            $fixes = self::decoded(self::text($id, 'fixes'));
            self::assertSame(5, $fixes['rev'] ?? null);
            self::assertContains(self::dig($fixes, 'items', 'text:sub', 'text'), ['první', 'druhý']);
            self::won('two tabs, one item', (string) json_encode(self::dig($fixes, 'items', 'text:sub', 'text'), JSON_UNESCAPED_UNICODE));
            self::assertSame('ready', self::text($id, 'status'));
            self::assertSame(0, $this->newTemplates());
            $this->forget();
        }
    }

    // ---- racers ------------------------------------------------------------

    /**
     * The accept reaches its row lock later than a purge or a fix reaches
     * theirs (it is the longer message), so released together it would always
     * come second. Every other round the competitor therefore starts 80 ms
     * late — inside the accept, which holds the lock while it renders — so
     * both orders are exercised.
     *
     * @template T
     * @param \Closure(ContainerInterface): T $racer
     * @return \Closure(ContainerInterface): T
     */
    private static function headStart(int $round, \Closure $racer): \Closure
    {
        return static function (ContainerInterface $c) use ($round, $racer): mixed {
            if ($round % 2 === 1) {
                usleep(80_000);
            }

            return $racer($c);
        };
    }

    /**
     * The real accept: the handler's answer, or the refusal's code.
     *
     * @return \Closure(ContainerInterface): mixed
     */
    private static function accept(string $id): \Closure
    {
        return static function (ContainerInterface $c) use ($id): mixed {
            try {
                return $c->get(MessageBusInterface::class)
                    ->dispatch(new AcceptTemplateScan(Uuid::fromString($id), Uuid::fromString(TestDataFixture::USER_1_ID)))
                    ->last(HandledStamp::class)?->getResult();
            } catch (HandlerFailedException $failed) {
                $refused = $failed->getPrevious();

                if ($refused instanceof AiScanRefused) {
                    return $refused->refusal->value;
                }

                throw $failed;
            }
        };
    }

    /**
     * @param array<string, mixed> $fix
     * @return \Closure(ContainerInterface): string
     */
    private static function fix(string $id, array $fix): \Closure
    {
        return static fn (ContainerInterface $c): string => self::dispatched(
            $c,
            new ApplyAiScanFix(Uuid::fromString($id), Uuid::fromString(TestDataFixture::USER_1_ID), $fix),
        ) ?? 'saved';
    }

    /** Null when the message was handled, else the refusal's code. */
    private static function dispatched(ContainerInterface $container, object $message): null|string
    {
        try {
            $container->get(MessageBusInterface::class)->dispatch($message);
        } catch (HandlerFailedException $failed) {
            $refused = $failed->getPrevious();

            if ($refused instanceof AiScanRefused) {
                return $refused->refusal->value;
            }

            throw $failed;
        }

        return null;
    }

    // ---- set-up ------------------------------------------------------------

    /** A committed `ready` scan with the rich proposal, made the way the scanner makes it. */
    private function proposal(): string
    {
        return self::ready($this->client, self::PROJECT);
    }

    /** The canvas of the template variant the scan was accepted into. */
    private function canvasOf(string $scanId): string
    {
        $canvas = self::connection()->fetchOne('SELECT canvas FROM template_variant WHERE id = :id', ['id' => self::text($scanId, 'result_variant_id')]);

        return is_string($canvas) ? $canvas : '';
    }

    private function newTemplates(): int
    {
        /** @var list<string> $now */
        $now = self::connection()->fetchFirstColumn('SELECT id FROM template');

        return count(array_diff($now, $this->templatesBefore));
    }

    /** Between rounds: the scans and whatever they created. */
    private function forget(): void
    {
        $this->clean(false);
    }

    /**
     * Everything this suite commits, removed: scans, their ledger and usage,
     * the templates accepted from them, the gallery rows an accept makes
     * (the project's stand-in file, the scan folders) and the files.
     */
    private function clean(bool $all = true): void
    {
        $connection = self::connection();

        /** @var list<string> $templates */
        $templates = $connection->fetchFirstColumn('SELECT id FROM template');

        foreach (array_diff($templates, $this->templatesBefore) as $templateId) {
            $connection->executeStatement('DELETE FROM template WHERE id = :id', ['id' => $templateId]);
        }

        $connection->executeStatement('DELETE FROM ai_scan');
        $connection->executeStatement('DELETE FROM ai_credit_ledger');
        $connection->executeStatement('DELETE FROM ai_usage_event');
        $connection->executeStatement("DELETE FROM file_upload WHERE source = 'scan_stand_in'");
        $connection->executeStatement("DELETE FROM file_directory WHERE system_key LIKE 'ai-scan%'");
        $connection->executeStatement('DELETE FROM messenger_messages');
        $connection->executeStatement("UPDATE ai_scan_runtime SET max_running = 1, consecutive_failures = 0, failure_class = NULL, provider_state = 'ok'");

        if ($all) {
            $connection->executeStatement('DELETE FROM ai_scan_runtime');
            $connection->executeStatement('UPDATE project SET ai_scan_enabled = false, ai_scan_monthly_limit = 10, ai_scan_enabled_at = NULL, ai_scan_enabled_by_id = NULL');
            $connection->executeStatement('UPDATE "user" SET ai_scan_consent_version = NULL, ai_scan_consent_at = NULL');
        }

        (new \Symfony\Component\Filesystem\Filesystem())->remove('/tmp/wboost-test-private/ai-scan');
    }
}
