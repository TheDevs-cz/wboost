<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Calendar;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Services\Calendar\CalendarContentRules;
use WBoost\Web\Services\Calendar\CalendarEffectRules;

/**
 * The layer effects a page may store for itself (`pageEffects`) are checked by
 * PHP (`CalendarEffectRules`) and drawn by JS (`assets/calendar/model/effects.js`).
 * This pins the two vocabularies — kinds and their keys, blend modes, gradient
 * types, targets, caps and ranges — to each other.
 */
final class CalendarEffectContractTest extends TestCase
{
    public function testThePhpRulesMirrorTheJsModel(): void
    {
        $node = (new ExecutableFinder())->find('node');

        if ($node === null) {
            self::markTestSkipped('node is not installed');
        }

        $root = dirname(__DIR__, 2);
        $process = new Process([$node, $root . '/tests/Fixtures/calendar/dump-effects.mjs']);
        $process->setInput(json_encode(['root' => $root], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var array<string, mixed> $js */
        $js = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        if (isset($js['skip'])) {
            self::markTestSkipped((string) (is_string($js['skip']) ? $js['skip'] : 'skipped'));
        }

        $kinds = $js['kinds'];
        self::assertIsArray($kinds);
        ksort($kinds);
        $keys = CalendarEffectRules::KEYS;
        ksort($keys);
        self::assertSame($keys, $kinds);
        self::assertSame(CalendarEffectRules::BLEND_MODES, $js['blends']);
        self::assertSame(CalendarEffectRules::GRADIENT_TYPES, $js['gradientTypes']);
        self::assertSame(CalendarEffectRules::TARGETS, $js['targets']);
        self::assertSame(CalendarEffectRules::MAX_EFFECTS, $js['maxEffects']);
        self::assertSame(CalendarEffectRules::MAX_STOPS, $js['maxStops']);
        self::assertEquals(CalendarEffectRules::RANGES, $js['ranges']);

        self::assertIsArray($js['collection'], 'pageEffects is a JS content collection');
        self::assertTrue($js['collection']['pageKeyed'] ?? null, 'page-keyed in JS');
        self::assertFalse($js['collection']['clientWritable'] ?? null, 'designer only in JS');
        self::assertContains('pageEffects', CalendarContentRules::PAGE_KEYED);
        self::assertNotContains('pageEffects', CalendarContentRules::CLIENT_WRITABLE);
    }
}
