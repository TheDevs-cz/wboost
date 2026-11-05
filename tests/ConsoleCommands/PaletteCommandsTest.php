<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\ConsoleCommands;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * Smoke test of the read-only palette tooling (F7 P0) over the fixtures.
 */
final class PaletteCommandsTest extends KernelTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function testSnapshotIsDeterministicJson(): void
    {
        $first = $this->tempFile('json');
        $second = $this->tempFile('json');

        $tester = $this->tester('app:palette:snapshot');
        self::assertSame(0, $tester->execute(['out' => $first]));
        self::assertSame(0, $tester->execute(['out' => $second]));

        self::assertFileEquals($first, $second, 'two snapshots of unchanged data must be byte-identical');

        $snapshot = json_decode((string) file_get_contents($first), true);
        self::assertIsArray($snapshot);
        self::assertIsArray($snapshot['projects']);

        $project = null;
        foreach ($snapshot['projects'] as $candidate) {
            self::assertIsArray($candidate);
            if ($candidate['id'] === TestDataFixture::PROJECT_1_ID) {
                $project = $candidate;
            }
        }

        self::assertIsArray($project);
        self::assertArrayHasKey('computeColors', $project);
        self::assertArrayHasKey('brandCmykPalette', $project);
        self::assertArrayHasKey('hasPalette', $project);
        self::assertIsArray($project['avatar']);
        self::assertIsArray($project['manuals']);
        self::assertNotEmpty($project['manuals']);

        $manual = $project['manuals'][0];
        self::assertIsArray($manual);
        foreach (['colorsCount', 'detectedColors', 'customColors', 'primaryColors', 'secondaryColors', 'colorN', 'logo'] as $key) {
            self::assertArrayHasKey($key, $manual);
        }
        self::assertIsArray($manual['logo']);
        self::assertCount(5, $manual['logo']);
        self::assertIsArray($manual['logo']['horizontal']);
        self::assertIsArray($manual['logo']['horizontal']['colorVariants']);
        self::assertCount(5, $manual['logo']['horizontal']['colorVariants']);
    }

    public function testPlanIsReadOnlyAndWritesTheReport(): void
    {
        $tester = $this->tester('app:palette:plan');
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $tables = ['manual', 'project'];
        $before = array_map(static fn (string $table): mixed => $connection->fetchOne("SELECT md5(string_agg(t::text, ',' ORDER BY t::text)) FROM {$table} t"), $tables);

        $report = $this->tempFile('csv');

        self::assertSame(0, $tester->execute(['--report' => $report]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('Palette colours (project_color)', $display);
        self::assertStringContainsString('Role changes per manual', $display);
        self::assertStringContainsString('nothing was written', $display);

        $lines = file($report);
        self::assertIsArray($lines);
        self::assertSame("type,project_id,project,manual_id,manual,hex,detail\n", $lines[0]);

        $after = array_map(static fn (string $table): mixed => $connection->fetchOne("SELECT md5(string_agg(t::text, ',' ORDER BY t::text)) FROM {$table} t"), $tables);
        self::assertSame($before, $after);
    }

    private function tester(string $name): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find($name));
    }

    private function tempFile(string $extension): string
    {
        $file = sys_get_temp_dir() . '/palette-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $this->files[] = $file;

        return $file;
    }
}
