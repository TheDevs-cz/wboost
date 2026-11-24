<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Calendar;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Services\Calendar\CalendarLayerRules;

/**
 * The own layers of a layout (K20) are normalised by JS
 * (`assets/calendar/model/layers.js`) and checked by PHP
 * (`CalendarLayerRules`). This pins the two vocabularies — kinds and their
 * keys, enums, caps and ranges — to each other, and feeds a fresh layer of
 * every kind through the PHP rules.
 */
final class CalendarLayerContractTest extends TestCase
{
    public function testThePhpRulesMirrorTheJsModel(): void
    {
        $node = (new ExecutableFinder())->find('node');

        if ($node === null) {
            self::markTestSkipped('node is not installed');
        }

        $root = dirname(__DIR__, 2);
        $process = new Process([$node, $root . '/tests/Fixtures/calendar/dump-layers.mjs']);
        $process->setInput(json_encode(['root' => $root], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var array<string, mixed> $js */
        $js = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        if (isset($js['skip'])) {
            self::markTestSkipped((string) (is_string($js['skip']) ? $js['skip'] : 'skipped'));
        }

        self::assertSame(CalendarLayerRules::KINDS, $js['kinds']);
        self::assertSame(CalendarLayerRules::KEYS, $js['keys']);
        self::assertSame(CalendarLayerRules::FILL_KEYS, $js['fillKeys']);
        self::assertSame(CalendarLayerRules::STROKE_KEYS, $js['strokeKeys']);
        self::assertSame(CalendarLayerRules::LINE_STROKE_KEYS, $js['lineStrokeKeys']);
        self::assertSame(CalendarLayerRules::PAGE_SETS, $js['pageSets']);
        self::assertSame(CalendarLayerRules::STROKE_STYLES, $js['strokeStyles']);
        self::assertSame(CalendarLayerRules::LINE_CAPS, $js['lineCaps']);
        self::assertSame(CalendarLayerRules::FITS, $js['fits']);
        self::assertSame(CalendarLayerRules::MAX_LAYERS, $js['maxLayers']);
        self::assertSame(CalendarLayerRules::MAX_NAME_LENGTH, $js['maxName']);
        self::assertSame(CalendarLayerRules::MAX_PAGES, $js['maxPages']);
        self::assertEquals(CalendarLayerRules::RANGES, $js['ranges']);

        $samples = json_decode((string) json_encode($js['samples'], JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $samples);
        self::assertSame(['image', 'rect', 'ellipse', 'line'], CalendarLayerRules::layers($samples));
    }
}
