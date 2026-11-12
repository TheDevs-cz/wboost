<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\FaultInjection;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The barrier the fault-injection suites share: every racer is a forked
 * PROCESS with its own kernel and its own database connection, released at
 * the same instant.
 */
trait RacesProcesses
{
    /** @var array<string, array<string, int>> which side won, per race — printed with RACE_TALLY=1 */
    private static array $tally = [];

    private static function won(string $race, string $side): void
    {
        self::$tally[$race][$side] = (self::$tally[$race][$side] ?? 0) + 1;
    }

    private static function printTally(): void
    {
        if (getenv('RACE_TALLY') === false || self::$tally === []) {
            return;
        }

        foreach (self::$tally as $race => $sides) {
            ksort($sides);
            fwrite(STDERR, sprintf("\n[race] %s: %s", $race, implode(', ', array_map(static fn (string $side, int $count): string => $side . ' × ' . $count, array_keys($sides), $sides))));
        }

        fwrite(STDERR, "\n");
        self::$tally = [];
    }

    /**
     * Runs every racer in its own process — own kernel, own connection —
     * released together, and returns what each returned, in order.
     *
     * @param \Closure(ContainerInterface): mixed ...$racers
     * @return list<mixed>
     */
    private function race(\Closure ...$racers): array
    {
        // No connection may be shared across the fork.
        self::connection()->close();

        $start = microtime(true) + 1.2;
        $files = [];
        $pids = [];

        foreach (array_values($racers) as $index => $racer) {
            $files[$index] = sprintf('%s/ai-scan-race-%s-%d', sys_get_temp_dir(), bin2hex(random_bytes(4)), $index);
            $pid = pcntl_fork();

            if ($pid === -1) {
                self::fail('fork failed');
            }

            if ($pid === 0) {
                try {
                    // A kernel of its own (the parent's connection was closed
                    // before the fork, so shutting its copy down touches nothing shared).
                    self::ensureKernelShutdown();
                    self::bootKernel();
                    $container = self::getContainer();
                    // Open the connection before the barrier, so the race is the statement, not the handshake.
                    $container->get(EntityManagerInterface::class)->getConnection()->executeQuery('SELECT 1');

                    $wait = (int) (($start - microtime(true)) * 1_000_000);

                    if ($wait > 0) {
                        usleep($wait);
                    }

                    $result = ['ok' => $racer($container)];
                } catch (\Throwable $failure) {
                    $result = ['error' => $failure::class . ': ' . $failure->getMessage()];
                }

                file_put_contents($files[$index], serialize($result));
                // No shutdown handlers, no destructors: this copy of the test process just ends.
                posix_kill(posix_getpid(), SIGKILL);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];

        foreach ($files as $file) {
            $result = is_file($file) ? unserialize((string) file_get_contents($file)) : ['error' => 'the racer left no result'];
            @unlink($file);

            if (!is_array($result) || array_key_exists('error', $result)) {
                self::fail('A racer failed: ' . (is_array($result) && is_string($result['error'] ?? null) ? $result['error'] : 'unknown'));
            }

            $results[] = $result['ok'] ?? null;
        }

        return $results;
    }

}
