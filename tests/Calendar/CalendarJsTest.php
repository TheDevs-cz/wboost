<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Calendar;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs the node suites of the calendar's pure ES modules
 * (`assets/calendar/{model,generate,render,editor}`, later `state/`): one
 * PHPUnit case per `tests/Fixtures/calendar/suites/*.mjs`, so adding a suite
 * file needs no PHP edit. See docs/calendar/architecture.md §F.
 *
 * The modules are extension-typed `.js` files in a tree without
 * `"type": "module"`, which needs node >= 22.7; the harness answers
 * `{"skip": …}` on anything older and the case is skipped.
 */
final class CalendarJsTest extends TestCase
{
    private const string HARNESS = '/tests/Fixtures/calendar/harness.mjs';

    private const string SUITES = '/tests/Fixtures/calendar/suites';

    /**
     * @return iterable<string, array{string}>
     */
    public static function suites(): iterable
    {
        $files = glob(dirname(__DIR__, 2) . self::SUITES . '/*.mjs');
        self::assertNotFalse($files);

        foreach ($files as $file) {
            $suite = basename($file, '.mjs');

            yield $suite => [$suite];
        }
    }

    #[DataProvider('suites')]
    public function testSuite(string $suite): void
    {
        $node = (new ExecutableFinder())->find('node');

        if ($node === null) {
            self::markTestSkipped('node is not installed');
        }

        $root = dirname(__DIR__, 2);
        $process = new Process([$node, $root . self::HARNESS]);
        $process->setInput(json_encode(['root' => $root, 'suite' => $suite], JSON_THROW_ON_ERROR));
        $process->setTimeout(120);
        $process->mustRun();

        /** @var array{skip?: string, passed?: int, failures?: list<array{name: string, message: string}>} $result */
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        if (isset($result['skip'])) {
            self::markTestSkipped($result['skip']);
        }

        $failures = $result['failures'] ?? [];
        $report = implode("\n\n", array_map(
            static fn (array $failure): string => '✗ ' . $failure['name'] . "\n  " . str_replace("\n", "\n  ", $failure['message']),
            $failures,
        ));

        self::assertSame([], $failures, sprintf("%d failing test(s) in the calendar suite \"%s\":\n\n%s\n", count($failures), $suite, $report));
        self::assertGreaterThan(0, $result['passed'] ?? 0, sprintf('The calendar suite "%s" ran no tests.', $suite));
    }

    public function testThereAreSuites(): void
    {
        self::assertGreaterThanOrEqual(15, iterator_count(self::suites()));
    }
}
