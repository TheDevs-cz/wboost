<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\ConsoleCommands;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * Smoke test of the palette snapshot (F7 P0) over the fixtures — since P5a
 * the only palette tool besides `app:palette:merge-duplicates` (the JSONB
 * plan / verify / reconcile commands were retired with the columns).
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

    public function testMergeDuplicatesIsADryRunUnlessApplied(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $connection->executeStatement(
            "INSERT INTO project_color (id, project_id, hex, hex_key, role, pantone, position, created_at) VALUES ('0f7c0000-0000-7000-8000-0000000000d9', ?, '004e7c', '#004e7c', NULL, '301 C', 5, NOW())",
            [TestDataFixture::PROJECT_1_ID],
        );
        $count = static fn (): mixed => $connection->fetchOne("SELECT COUNT(*) FROM project_color WHERE project_id = ? AND hex_key = '#004e7c'", [TestDataFixture::PROJECT_1_ID]);

        $tester = $this->tester('app:palette:merge-duplicates');
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('#004E7C (2 colours)', $tester->getDisplay());
        self::assertStringContainsString('dry run', $tester->getDisplay());
        self::assertSame(2, $count());

        self::assertSame(0, $tester->execute(['--apply' => true]));
        self::assertSame(1, $count());
        // The survivor (the one Manual 1 links) gained the loser's Pantone.
        self::assertSame('301 C', $connection->fetchOne('SELECT pantone FROM project_color WHERE id = ?', [TestDataFixture::PROJECT_1_COLOR_BLUE_ID]));
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
