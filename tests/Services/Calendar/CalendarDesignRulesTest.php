<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Calendar;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\InvalidCalendarChange;
use WBoost\Web\Services\Calendar\CalendarDesignRules;
use WBoost\Web\Services\Calendar\CalendarLayoutAssets;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\Calendar\CalendarType;

/**
 * Structural validation of a design patch (docs/calendar/architecture.md
 * §C.5 step 3) and the asset walk of a layout.
 *
 * @covers \WBoost\Web\Services\Calendar\CalendarDesignRules
 * @covers \WBoost\Web\Services\Calendar\CalendarLayoutAssets
 */
final class CalendarDesignRulesTest extends TestCase
{
    private const string PAGE = '{"width": 310, "height": 145, "bandHeight": 12.5, "holes": {"size": 4.3, "radius": 0.6, "pitch": 8, "top": 4, "margin": 4.5}}';
    private const string PRINT = '{"bleed": true, "bleedMm": 5, "marks": false}';
    private const string FEATURES = '{"captions": true, "weekNumbers": true, "moon": true, "timeChange": false, "seasons": false, "waste": false, "events": false, "shifts": false, "specialPages": false, "qr": false}';

    public function testAFullPatchIsNormalised(): void
    {
        $design = self::validate(sprintf(
            '{"name": "  Obecní \n kalendář ", "year": 2028, "type": "fortnight", "page": %s, "print": %s, "features": %s, "languages": ["pl", "en"], "preset": "twoSided", "layout": %s, "linkElements": false, "step": 4, "finished": true}',
            self::PAGE,
            self::PRINT,
            self::FEATURES,
            TestDataFixture::CALENDAR_LAYOUT_JSON,
        ));

        self::assertSame(CalendarDesignRules::KEYS, array_keys($design));
        self::assertSame('Obecní kalendář', $design['name'] ?? null);
        self::assertSame(2028, $design['year'] ?? null);
        self::assertSame(CalendarType::Fortnight, $design['type'] ?? null);
        self::assertSame(['width' => 310, 'height' => 145, 'bandHeight' => 12.5, 'holes' => ['size' => 4.3, 'radius' => 0.6, 'pitch' => 8, 'top' => 4, 'margin' => 4.5]], $design['page'] ?? null);
        self::assertSame(['bleed' => true, 'bleedMm' => 5, 'marks' => false], $design['print'] ?? null);
        self::assertSame(CalendarDesignRules::FEATURES, array_keys($design['features'] ?? []));
        self::assertSame(['pl', 'en'], $design['languages'] ?? null);
        self::assertSame('twoSided', $design['preset'] ?? null);
        self::assertFalse($design['linkElements'] ?? null);
        self::assertSame(4, $design['step'] ?? null);
        self::assertTrue($design['finished'] ?? null);
        // The layout is kept as the object tree it arrived as.
        self::assertEquals(json_decode(TestDataFixture::CALENDAR_LAYOUT_JSON), $design['layout'] ?? null);
    }

    public function testAPatchMayCarryAnySubsetIncludingNulls(): void
    {
        self::assertSame([], self::validate('{}'));
        self::assertSame(['step' => 2], self::validate('{"step": 2}'));
        self::assertSame(['preset' => null, 'layout' => null], self::validate('{"preset": null, "layout": null}', CalendarType::Week, 'photoLeft'));
    }

    public function testLanguagesAreAListOfExtraCodesInPrintOrder(): void
    {
        self::assertSame(['languages' => []], self::validate('{"languages": []}'));
        self::assertSame(['languages' => ['sk', 'pl', 'de', 'en']], self::validate('{"languages": ["sk", "pl", "de", "en"]}'));
    }

    public function testThePresetMustFitTheTypeAfterThePatch(): void
    {
        self::assertSame(['preset' => 'photoRight'], self::validate('{"preset": "photoRight"}'));
        self::assertSame(['preset' => 'twoSided'], self::validate('{"preset": "twoSided"}', CalendarType::Fortnight));
        self::validate('{"type": "fortnight", "preset": "twoSided"}', CalendarType::Week, 'photoLeft');
        self::validate('{"type": "fortnight"}', CalendarType::Week, null);

        self::assertInvalid('{"preset": "twoSided"}');
        self::assertInvalid('{"preset": "photoLeft"}', CalendarType::Fortnight);
        // The stored preset would no longer fit the new type.
        self::assertInvalid('{"type": "fortnight"}', CalendarType::Week, 'photoLeft');
        self::assertInvalid('{"type": "week"}', CalendarType::Fortnight, 'twoSided');
    }

    #[DataProvider('provideInvalidPatches')]
    public function testInvalidPatchesAreRefused(string $json): void
    {
        self::assertInvalid($json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidPatches(): iterable
    {
        yield 'unknown key' => ['{"revision": 9}'];
        yield 'name not a string' => ['{"name": 5}'];
        yield 'name empty' => ['{"name": " "}'];
        yield 'name 61 characters' => [sprintf('{"name": "%s"}', str_repeat('ž', 61))];
        yield 'year 1999' => ['{"year": 1999}'];
        yield 'year 2101' => ['{"year": 2101}'];
        yield 'year float' => ['{"year": 2027.5}'];
        yield 'type' => ['{"type": "month"}'];
        yield 'preset' => ['{"preset": "scan"}'];
        yield 'step 0' => ['{"step": 0}'];
        yield 'step 6' => ['{"step": 6}'];
        yield 'step string' => ['{"step": "3"}'];
        yield 'linkElements' => ['{"linkElements": 1}'];
        yield 'languages not a list' => ['{"languages": {"pl": true}}'];
        yield 'languages unknown code' => ['{"languages": ["pl", "fr"]}'];
        yield 'languages Czech is implied' => ['{"languages": ["cs"]}'];
        yield 'languages twice' => ['{"languages": ["pl", "pl"]}'];
        yield 'languages out of print order' => ['{"languages": ["en", "pl"]}'];
        yield 'finished' => ['{"finished": "yes"}'];
        yield 'page not an object' => ['{"page": [310, 145]}'];
        yield 'page empty' => ['{"page": {}}'];
        yield 'page extra key' => [sprintf('{"page": %s}', str_replace('"width"', '"dpi": 300, "width"', self::PAGE))];
        yield 'page width 49' => [sprintf('{"page": %s}', str_replace('310', '49', self::PAGE))];
        yield 'page height 2001' => [sprintf('{"page": %s}', str_replace('145', '2001', self::PAGE))];
        yield 'page width string' => [sprintf('{"page": %s}', str_replace('310', '"310"', self::PAGE))];
        yield 'page holes missing key' => [sprintf('{"page": %s}', str_replace(', "margin": 4.5', '', self::PAGE))];
        yield 'print bleedMm 10.5' => [sprintf('{"print": %s}', str_replace('"bleedMm": 5', '"bleedMm": 10.5', self::PRINT))];
        yield 'print bleed not a boolean' => [sprintf('{"print": %s}', str_replace('"bleed": true', '"bleed": 1', self::PRINT))];
        yield 'print missing key' => ['{"print": {"bleed": true, "bleedMm": 5}}'];
        yield 'features missing key' => ['{"features": {"captions": true}}'];
        yield 'features not boolean' => [sprintf('{"features": %s}', str_replace('"qr": false', '"qr": 0', self::FEATURES))];
        yield 'layout not an object' => ['{"layout": []}'];
    }

    public function testFixtureLayoutIsValid(): void
    {
        $layout = json_decode(TestDataFixture::CALENDAR_LAYOUT_JSON);

        self::assertSame($layout, (new CalendarDesignRules())->layout($layout));
    }

    /**
     * A 14-day layout: Měsíc and Číslo týdne have a second frame beside the
     * second Kalendárium frame; the eye of the element stays on the first one.
     */
    public function testSecondFramesAreAcceptedBesideTheSecondKalendarium(): void
    {
        /** @var array<string, mixed> $layout */
        $layout = json_decode(TestDataFixture::CALENDAR_LAYOUT_JSON, true, 64, JSON_THROW_ON_ERROR);
        $box = ['x' => 0, 'y' => 0, 'w' => 10, 'h' => 10];
        $layout['boxes'] = [
            'cal' => $box,
            'cal2' => $box,
            'month' => [...$box, 'hidden' => true],
            'month2' => $box,
            'wk' => [...$box, 'hidden' => true],
            'wk2' => $box,
        ];

        $fortnight = json_decode(json_encode($layout, JSON_THROW_ON_ERROR));

        self::assertSame($fortnight, (new CalendarDesignRules())->layout($fortnight));
    }

    /**
     * @param array<string, mixed> $change replaces top-level keys of the fixture layout
     */
    #[DataProvider('provideInvalidLayouts')]
    public function testInvalidLayoutsAreRefused(array $change): void
    {
        /** @var array<string, mixed> $layout */
        $layout = json_decode(TestDataFixture::CALENDAR_LAYOUT_JSON, true, 64, JSON_THROW_ON_ERROR);

        $this->expectException(InvalidCalendarChange::class);

        (new CalendarDesignRules())->layout(json_decode(json_encode([...$layout, ...$change], JSON_THROW_ON_ERROR)));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideInvalidLayouts(): iterable
    {
        $box = ['x' => 0, 'y' => 0, 'w' => 10, 'h' => 10];

        yield 'version 2' => [['v' => 2]];
        yield 'version as text' => [['v' => '1']];
        yield 'unknown top-level key' => [['guides' => []]];
        yield 'boxes a list' => [['boxes' => [$box]]];
        yield 'unknown box' => [['boxes' => ['bg' => $box]]];
        yield 'box missing h' => [['boxes' => ['photo' => ['x' => 0, 'y' => 0, 'w' => 10]]]];
        yield 'box with text' => [['boxes' => ['photo' => [...$box, 'x' => '0']]]];
        yield 'box zero width' => [['boxes' => ['photo' => [...$box, 'w' => 0]]]];
        yield 'box negative height' => [['boxes' => ['photo' => [...$box, 'h' => -4]]]];
        yield 'box far away' => [['boxes' => ['photo' => [...$box, 'x' => 5001]]]];
        yield 'box extra key' => [['boxes' => ['photo' => [...$box, 'rotate' => 5]]]];
        yield 'hidden false' => [['boxes' => ['logo' => [...$box, 'hidden' => false]]]];
        yield 'hidden on photo' => [['boxes' => ['photo' => [...$box, 'hidden' => true]]]];
        yield 'hidden on cap' => [['boxes' => ['cap' => [...$box, 'hidden' => true]]]];
        yield 'second month frame without cal2' => [['boxes' => ['month' => $box, 'month2' => $box]]];
        yield 'second week-number frame without cal2' => [['boxes' => ['wk' => $box, 'wk2' => $box]]];
        yield 'hidden on the second month frame' => [['boxes' => ['cal2' => $box, 'month2' => [...$box, 'hidden' => true]]]];
        yield 'hidden on the second week-number frame' => [['boxes' => ['cal2' => $box, 'wk2' => [...$box, 'hidden' => true]]]];
        yield 'order a map' => [['order' => ['a' => 'photo']]];
        yield 'order unknown element' => [['order' => ['photo', 'cal2']]];
        yield 'order with bg' => [['order' => ['photo', 'bg']]];
        yield 'order duplicate' => [['order' => ['photo', 'cal', 'photo']]];
        yield 'styles a list' => [['styles' => [1, 2]]];
        yield 'style string of 256' => [['styles' => ['month' => ['family' => str_repeat('a', 256)]]]];
        yield 'styles nine levels deep' => [['styles' => ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => ['i' => 1]]]]]]]]]]];
    }

    /**
     * Own layers (K20): the items in `layers`, each with exactly one `u:<id>`
     * box (which may be hidden) and one `u:<id>` entry in `order`.
     */
    public function testOwnLayersAreAccepted(): void
    {
        $layout = self::withLayers([
            'stripe' => ['kind' => 'rect', 'name' => 'Pruh', 'pages' => 'weeks', 'except' => [], 'opacity' => 60, 'blend' => 'multiply', 'fill' => ['on' => true, 'color' => '#E5DDF7', 'opacity' => 100], 'stroke' => ['on' => false, 'color' => '#2B2830', 'width' => 1, 'style' => 'dash'], 'radius' => 3],
            'logos' => ['kind' => 'image', 'name' => '', 'pages' => ['w05', 'cover', 'sp:intro'], 'except' => [], 'opacity' => 100, 'blend' => 'normal', 'asset' => '0196f000-0000-7000-8000-00000000000a', 'fit' => 'contain', 'radius' => 0],
            'dot' => ['kind' => 'ellipse', 'name' => 'Kruh', 'pages' => 'all', 'except' => ['back'], 'opacity' => 0, 'blend' => 'normal', 'fill' => ['on' => true, 'color' => '#c84b55', 'opacity' => 50], 'stroke' => ['on' => true, 'color' => '#000000', 'width' => 20, 'style' => 'dot']],
            'rule' => ['kind' => 'line', 'stroke' => ['color' => '#2B2830', 'width' => 0.1, 'style' => 'solid', 'cap' => 'round']],
        ], hidden: 'rule');

        self::assertSame($layout, (new CalendarDesignRules())->layout($layout));
        self::assertSame(['0196f000-0000-7000-8000-00000000000a'], CalendarLayoutAssets::ids($layout));

        // an empty map that went through PHP arrays comes back as a list
        $empty = self::withLayers([]);
        $empty->layers = [];
        self::assertSame($empty, (new CalendarDesignRules())->layout($empty));
    }

    /**
     * @param array<string, mixed> $layers
     * @param array<string, mixed> $change replaces top-level keys after the layers were added
     */
    #[DataProvider('provideInvalidLayers')]
    public function testInvalidOwnLayersAreRefused(array $layers, array $change = []): void
    {
        $layout = self::withLayers($layers);

        foreach ($change as $key => $value) {
            $layout->{$key} = json_decode(json_encode($value, JSON_THROW_ON_ERROR));
        }

        $this->expectException(InvalidCalendarChange::class);

        (new CalendarDesignRules())->layout($layout);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1?: array<string, mixed>}>
     */
    public static function provideInvalidLayers(): iterable
    {
        $rect = ['kind' => 'rect'];

        yield 'unknown kind' => [['a' => ['kind' => 'text']]];
        yield 'no kind' => [['a' => ['name' => 'x']]];
        yield 'key of another kind' => [['a' => ['kind' => 'line', 'radius' => 3]]];
        yield 'a later key (effects come in P3)' => [['a' => ['kind' => 'rect', 'effects' => []]]];
        yield 'bad id' => [['a b' => $rect]];
        yield 'reserved id' => [['constructor' => $rect]];
        yield 'name too long' => [['a' => ['kind' => 'rect', 'name' => str_repeat('x', 61)]]];
        yield 'name with a line break' => [['a' => ['kind' => 'rect', 'name' => "a\nb"]]];
        yield 'opacity over 100' => [['a' => ['kind' => 'rect', 'opacity' => 101]]];
        yield 'blend outside the 16' => [['a' => ['kind' => 'rect', 'blend' => 'dissolve']]];
        yield 'page set unknown' => [['a' => ['kind' => 'rect', 'pages' => 'odd']]];
        yield 'page key unknown' => [['a' => ['kind' => 'rect', 'pages' => ['w5']]]];
        yield 'page key twice' => [['a' => ['kind' => 'rect', 'pages' => ['w05', 'w05']]]];
        yield 'except beside a list' => [['a' => ['kind' => 'rect', 'pages' => ['w05'], 'except' => ['w05']]]];
        yield 'fill colour' => [['a' => ['kind' => 'rect', 'fill' => ['color' => 'red']]]];
        yield 'fill extra key' => [['a' => ['kind' => 'rect', 'fill' => ['gradient' => []]]]];
        yield 'stroke too thick' => [['a' => ['kind' => 'rect', 'stroke' => ['width' => 21]]]];
        yield 'stroke cap on a rect' => [['a' => ['kind' => 'rect', 'stroke' => ['cap' => 'round']]]];
        yield 'line cap unknown' => [['a' => ['kind' => 'line', 'stroke' => ['cap' => 'square']]]];
        yield 'image fit unknown' => [['a' => ['kind' => 'image', 'fit' => 'fill']]];
        yield 'image asset a number' => [['a' => ['kind' => 'image', 'asset' => 5]]];
        yield 'layers a list' => [[], ['layers' => [$rect]]];
        yield 'box of an unknown layer' => [['a' => $rect], ['boxes' => ['u:a' => ['x' => 0, 'y' => 0, 'w' => 10, 'h' => 10], 'u:b' => ['x' => 0, 'y' => 0, 'w' => 10, 'h' => 10]]]];
        yield 'layer without a box' => [['a' => $rect], ['boxes' => new \stdClass()]];
        yield 'layer without an order entry' => [['a' => $rect], ['order' => ['photo']]];
        yield 'order entry of an unknown layer' => [['a' => $rect], ['order' => ['u:a', 'u:b']]];
        yield 'too many layers' => [array_fill_keys(array_map(static fn (int $i): string => 'l' . $i, range(1, 51)), $rect)];
    }

    /**
     * The fixture layout plus `layers`, a box and an order entry per layer.
     *
     * @param array<string, mixed> $layers
     */
    private static function withLayers(array $layers, null|string $hidden = null): \stdClass
    {
        /** @var array<string, mixed> $layout */
        $layout = json_decode(TestDataFixture::CALENDAR_LAYOUT_JSON, true, 64, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $boxes */
        $boxes = $layout['boxes'];
        /** @var list<string> $order */
        $order = $layout['order'];

        foreach (array_keys($layers) as $id) {
            $boxes['u:' . $id] = ['x' => -5, 'y' => 60, 'w' => 320, 'h' => 10] + ($hidden === $id ? ['hidden' => true] : []);
            $order[] = 'u:' . $id;
        }

        $layout['boxes'] = $boxes;
        $layout['order'] = $order;
        $layout['layers'] = $layers === [] ? new \stdClass() : $layers;

        $object = json_decode(json_encode($layout, JSON_THROW_ON_ERROR));
        \assert($object instanceof \stdClass);

        return $object;
    }

    public function testLayoutSizeCapAndDepth(): void
    {
        /** @var array<string, mixed> $layout */
        $layout = json_decode(TestDataFixture::CALENDAR_LAYOUT_JSON, true, 64, JSON_THROW_ON_ERROR);
        $rules = new CalendarDesignRules();

        // Eight levels are fine (styles › cal › iconCells › grid › cell › … has five).
        $rules->layout(json_decode(json_encode([...$layout, 'styles' => ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => 1]]]]]]]]], JSON_THROW_ON_ERROR)));

        $this->expectException(InvalidCalendarChange::class);
        $this->expectExceptionMessage('Layout je příliš velký na uložení.');

        $styles = [];

        for ($i = 0; $i < 1000; $i++) {
            $styles['key' . $i] = str_repeat('x', 250);
        }

        $rules->layout(json_decode(json_encode([...$layout, 'styles' => $styles], JSON_THROW_ON_ERROR)));
    }

    public function testAssetReferencesAreFoundAndRewritten(): void
    {
        $a = '0196f000-0000-7000-8000-00000000000a';
        $b = '0196f000-0000-7000-8000-00000000000b';
        $c = '0196f000-0000-7000-8000-00000000000c';
        $d = '0196f000-0000-7000-8000-00000000000d';

        $layout = json_decode(sprintf(
            '{"v": 1, "boxes": {}, "order": [], "styles": {
                "logo": {"asset": "%1$s", "fit": "contain"},
                "head": {"bg": {"imageOn": true, "asset": "%2$s"}},
                "moon": {"icons": [null, "%3$s", null, "%1$s"]},
                "icons": {"order": ["waste"], "ev": {"name": "Akce", "asset": null}, "season": {"assets": ["%4$s", null, null, null]}},
                "waste": {"kinds": {"kom": {"color": null, "icon": "%2$s"}}}
            }}',
            strtoupper($a),
            $b,
            $c,
            $d,
        ));

        self::assertEqualsCanonicalizing([$a, $b, $c, $d], CalendarLayoutAssets::ids($layout));

        $new = '0196f000-0000-7000-8000-0000000000ff';
        $rewritten = CalendarLayoutAssets::rewrite($layout, [$a => $new, $c => null]);
        $json = (string) json_encode($rewritten);

        // `a` → new everywhere, everything unmapped → null; the source is untouched.
        self::assertSame([$new], CalendarLayoutAssets::ids($rewritten));
        self::assertStringContainsString(sprintf('"icons":[null,null,null,"%s"]', $new), $json);
        self::assertStringContainsString('"assets":[null,null,null,null]', $json);
        self::assertStringContainsString('"kom":{"color":null,"icon":null}', $json);
        self::assertStringContainsString('"order":["waste"]', $json);
        self::assertCount(4, CalendarLayoutAssets::ids($layout));

        // A reference that is not an asset id: refused when saving, skipped when importing a file.
        $foreign = json_decode('{"styles": {"logo": {"asset": "logo.png"}}}');
        self::assertSame([], CalendarLayoutAssets::ids($foreign, false));

        $this->expectException(InvalidCalendarChange::class);
        CalendarLayoutAssets::ids($foreign);
    }

    /**
     * @return array{name?: string, year?: int, type?: CalendarType, page?: array<string, mixed>, print?: array<string, mixed>, features?: array<string, bool>, languages?: list<string>, preset?: null|string, layout?: null|\stdClass, linkElements?: bool, step?: int, finished?: bool}
     */
    private static function validate(string $json, CalendarType $type = CalendarType::Week, null|string $preset = null): array
    {
        $patch = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $patch);

        return (new CalendarDesignRules())->validate($patch, $type, $preset);
    }

    private static function assertInvalid(string $json, CalendarType $type = CalendarType::Week, null|string $preset = null): void
    {
        try {
            self::validate($json, $type, $preset);
        } catch (InvalidCalendarChange $invalid) {
            self::assertNotSame('', $invalid->getMessage());

            return;
        }

        self::fail('Should be refused: ' . $json);
    }
}
