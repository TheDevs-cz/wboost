<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Calendar;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Services\Calendar\CalendarContentSeed;

/**
 * The server seeds a new calendar with four built-in waste kinds
 * (docs/calendar/architecture.md §B.6, deviation D5) while every other default
 * lives in JS. This pins the PHP seed to the JS table `BUILTIN_WASTE`
 * (`assets/calendar/model/defaults.js`) and to the item shape the JS modules
 * read, so the two cannot drift apart.
 */
final class CalendarSeedContractTest extends TestCase
{
    /** Keys of a `wasteKinds` item, architecture §B.6. */
    private const array WASTE_KIND_KEYS = ['builtin', 'name', 'on', 'place', 'mode', 'weekdays', 'interval', 'from', 'to', 'dates', 'pos'];

    public function testSeedNamesMatchTheJsTable(): void
    {
        $seed = self::seed();
        $js = self::jsDefaults($seed);

        self::assertSame(1, $seed['v']);
        self::assertSame(['kom', 'bio', 'plast', 'papir'], array_keys($seed['wasteKinds']));

        foreach ($seed['wasteKinds'] as $id => $kind) {
            self::assertArrayHasKey($id, $js['builtinWaste'], sprintf('"%s" is not a built-in waste kind in defaults.js', $id));
            self::assertSame($js['builtinWaste'][$id]['name'], $kind['name'], sprintf('Name of the built-in waste kind "%s"', $id));
            self::assertSame($id, $kind['builtin']);
        }

        // Sklo is built in (colour, name) but added by the user, not seeded.
        self::assertArrayHasKey('sklo', $js['builtinWaste']);
        self::assertArrayNotHasKey('sklo', $seed['wasteKinds']);
    }

    public function testSeedItemsHaveTheShapeTheJsModulesRead(): void
    {
        $seed = self::seed();
        $position = 0;

        foreach ($seed['wasteKinds'] as $id => $kind) {
            self::assertSame(self::WASTE_KIND_KEYS, array_keys($kind), sprintf('Keys of the seeded waste kind "%s"', $id));
            self::assertFalse($kind['on'], 'Seeded kinds are switched off');
            self::assertSame('', $kind['place']);
            self::assertSame('rule', $kind['mode']);
            self::assertSame([0], $kind['weekdays']);
            self::assertSame('w', $kind['interval']);
            self::assertNull($kind['from']);
            self::assertNull($kind['to']);
            self::assertSame([], $kind['dates']);
            self::assertSame($position++, $kind['pos']);
        }
    }

    public function testNormalisingTheSeedInJsKeepsItAndAddsEveryCollection(): void
    {
        $seed = self::seed();
        $js = self::jsDefaults($seed);

        self::assertSame($seed['wasteKinds'], $js['normalizedSeed']['wasteKinds']);
        self::assertSame(1, $js['normalizedSeed']['v']);

        foreach ($js['collections'] as $collection) {
            self::assertArrayHasKey($collection, $js['normalizedSeed']);
        }

        self::assertContains('wasteKinds', $js['clientCollections']);
    }

    /**
     * @return array{v: int, wasteKinds: array<string, array<string, mixed>>}
     */
    private static function seed(): array
    {
        if (!class_exists(CalendarContentSeed::class)) {
            self::markTestSkipped('CalendarContentSeed does not exist yet (phase P1).');
        }

        return CalendarContentSeed::initial();
    }

    /**
     * @param array<string, mixed> $seed
     *
     * @return array{builtinWaste: array<string, array{name: string, color: string}>, collections: list<string>, clientCollections: list<string>, normalizedSeed: array<string, mixed>}
     */
    private static function jsDefaults(array $seed): array
    {
        $node = (new ExecutableFinder())->find('node');

        if ($node === null) {
            self::markTestSkipped('node is not installed');
        }

        $root = dirname(__DIR__, 2);
        $process = new Process([$node, $root . '/tests/Fixtures/calendar/dump-defaults.mjs']);
        $process->setInput(json_encode(['root' => $root, 'seed' => $seed], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var array{skip?: string, builtinWaste?: array<string, array{name: string, color: string}>, collections?: list<string>, clientCollections?: list<string>, normalizedSeed?: array<string, mixed>} $result */
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        if (isset($result['skip'])) {
            self::markTestSkipped($result['skip']);
        }

        self::assertArrayHasKey('builtinWaste', $result);
        self::assertArrayHasKey('collections', $result);
        self::assertArrayHasKey('clientCollections', $result);
        self::assertArrayHasKey('normalizedSeed', $result);

        return [
            'builtinWaste' => $result['builtinWaste'],
            'collections' => $result['collections'],
            'clientCollections' => $result['clientCollections'],
            'normalizedSeed' => $result['normalizedSeed'],
        ];
    }
}
