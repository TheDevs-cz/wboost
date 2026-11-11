<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Tool;

use Doctrine\DBAL\Connection;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\Group;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Repository\CalendarAssetRepository;
use WBoost\Web\Repository\CalendarHistoryRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\Calendar\CalendarAuthorKind;
use WBoost\Web\Value\Calendar\CalendarType;

/**
 * `manage_calendar`, `set_calendar_layout`, `upload_calendar_asset`,
 * `render_calendar_page` — a calendar authored without a browser, driven end
 * to end over `/_mcp`.
 *
 * What is worth breaking the build over:
 *
 * - **One write path.** Every design change is the web's `SaveCalendar`: the
 *   server's rules refuse it, "Upravil vzhled a nastavení" is recorded under
 *   the token's user, the revision moves, and a refused call stores nothing.
 * - **The layout comes from the calendar JavaScript.** A preset applied here
 *   is the browser's default layout, fully materialised — never a PHP table.
 * - **A patch names what it changes, and an unknown key is refused by name.**
 * - **Role ∩ scope.** A member the project is only shared with holds
 *   `calendars:write` and still cannot touch the design; `calendars:read`
 *   looks (also at a rendered page) and writes nothing.
 * - **Deleting stays on the web**: no action of any tool removes a calendar.
 *
 * @covers \WBoost\Web\Mcp\Tool\ManageCalendarTool
 * @covers \WBoost\Web\Mcp\Tool\SetCalendarLayoutTool
 * @covers \WBoost\Web\Mcp\Tool\UploadCalendarAssetTool
 * @covers \WBoost\Web\Mcp\Tool\RenderCalendarPageTool
 * @covers \WBoost\Web\Mcp\Fill\CalendarWriter
 * @covers \WBoost\Web\Services\Calendar\CalendarModelBridge
 */
final class CalendarDesignToolsTest extends WebTestCase
{
    use CalendarMcpTesting;

    private const string SVG = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>';

    // -----------------------------------------------------------------
    // manage_calendar
    // -----------------------------------------------------------------

    public function testCreateMakesAConfiguredCalendarWithTheBrowsersDefaultLayout(): void
    {
        $created = $this->ok('manage_calendar', [
            'action' => 'create',
            'projectId' => TestDataFixture::PROJECT_1_ID,
            'name' => 'Kalendář obce Lhota',
            'year' => 2028,
            'type' => 'week',
            'features' => ['events' => true, 'waste' => true, 'moon' => false],
            'print' => ['bleedMm' => 3],
            'preset' => 'photoRight',
        ]);

        self::assertSame('Kalendář obce Lhota', $created['name']);
        self::assertSame(2028, $created['year']);
        self::assertSame('week', $created['type']);
        self::assertSame('photoRight', $created['preset']);
        self::assertSame(3, $created['step'], 'A calendar with a fresh layout opens in step 3, as on the web.');
        self::assertFalse($created['finished']);
        self::assertContains('created', self::arr($created['changed']));
        self::assertContains('layout', self::arr($created['changed']));
        self::assertTrue(self::dig($created, 'features', 'events'));
        self::assertFalse(self::dig($created, 'features', 'moon'));
        self::assertTrue(self::dig($created, 'features', 'captions'), 'A switch that was not named keeps its default.');
        self::assertEquals(3, self::dig($created, 'print', 'bleedMm'));

        // The default layout of the browser, mirrored: the photo is on the right.
        $boxes = self::arr(self::dig($created, 'layout', 'boxes'));
        self::assertGreaterThan(100, self::dig($boxes, 'photo', 'x'));
        self::assertLessThan(10, self::dig($boxes, 'cal', 'x'));
        self::assertSame('start', self::dig($created, 'layout', 'styles', 'cal', 'numberSide'));

        self::assertEqualsCanonicalizing(
            ['Vytvořil kalendář', 'Upravil vzhled a nastavení'],
            array_column(self::arr($created['recorded']), 'what'),
        );

        $stored = $this->calendar(self::str($created['calendarId']));
        self::assertSame(TestDataFixture::PROJECT_1_ID, $stored->project->id->toString());
        self::assertNotNull($stored->layout);
        self::assertSame('photoRight', $stored->layoutPreset);
        self::assertTrue($stored->features['waste']);
        self::assertArrayHasKey('kom', self::arr(self::dig($stored->content, 'wasteKinds')), 'The content seed is the web\'s.');

        $newest = self::getContainer()->get(CalendarHistoryRepository::class)->latest($stored->id, 1)[0];
        self::assertSame(CalendarAuthorKind::Designer, $newest->authorKind);
        self::assertSame(TestDataFixture::USER_1_ID, $newest->user?->id->toString());

        // And without any setting it is exactly what the web's button creates.
        $plain = $this->ok('manage_calendar', ['action' => 'create', 'projectId' => TestDataFixture::PROJECT_1_ID]);
        self::assertSame(['created'], $plain['changed']);
        self::assertNull($plain['layout']);
        self::assertSame(1, $plain['step']);
        self::assertStringStartsWith('Stolní kalendář ', self::str($plain['name']));
    }

    public function testARefusedCreateCreatesNothing(): void
    {
        $before = $this->calendarCount();

        self::assertStringEndsWith('(project_not_found)', $this->refused('manage_calendar', ['action' => 'create', 'projectId' => TestDataFixture::PROJECT_2_ID]));
        self::assertStringEndsWith('(invalid_project_id)', $this->refused('manage_calendar', ['action' => 'create', 'projectId' => 'projekt']));
        self::assertStringEndsWith('(missing_argument)', $this->refused('manage_calendar', ['action' => 'create']));

        // A 14-day preset on a 7-day calendar: checked before the calendar exists.
        $mismatch = $this->refused('manage_calendar', ['action' => 'create', 'projectId' => TestDataFixture::PROJECT_1_ID, 'preset' => 'twoSided']);
        self::assertStringEndsWith('(preset_type_mismatch)', $mismatch);

        self::assertStringEndsWith('(unknown_feature)', $this->refused('manage_calendar', ['action' => 'create', 'projectId' => TestDataFixture::PROJECT_1_ID, 'features' => ['weather' => true]]));

        // A member the project is only shared with is no designer.
        self::assertStringEndsWith('(designer_only)', $this->refused('manage_calendar', ['action' => 'create', 'projectId' => TestDataFixture::PROJECT_1_ID], TestDataFixture::MCP_TOKEN_CALENDARS_SHARED_USER));

        self::assertSame($before, $this->calendarCount());
    }

    public function testConfigureChangesSettingsThroughTheModelAndRecordsHistory(): void
    {
        $this->ok('update_calendar_content', ['calendarId' => TestDataFixture::CALENDAR_2_ID, 'operations' => [
            ['collection' => 'events', 'id' => 'Ev1tAb2cDe3fGh4i', 'value' => ['date' => '2027-06-01', 'name' => 'Den dětí', 'club' => null, 'icon' => null], 'label' => '1. 6.'],
        ]]);
        $revision = $this->calendar(TestDataFixture::CALENDAR_2_ID)->revision;

        $configured = $this->ok('manage_calendar', [
            'action' => 'configure',
            'calendarId' => TestDataFixture::CALENDAR_2_ID,
            'name' => '  Obecní   kalendář 2028 ',
            'year' => 2028,
            'features' => ['events' => true],
            'print' => ['bleed' => false],
        ]);

        self::assertSame('Obecní kalendář 2028', $configured['name']);
        self::assertSame(2028, $configured['year']);
        self::assertEqualsCanonicalizing(['name', 'year', 'features', 'print', 'layout', 'content'], self::arr($configured['changed']));
        self::assertStringContainsString('2027 to 2028', self::text($configured['notes']));
        // One save: the settings and the items the year change moved.
        self::assertEqualsCanonicalizing(['Upravil vzhled a nastavení', 'Upravil akci „Den dětí“'], array_column(self::arr($configured['recorded']), 'what'));

        $stored = $this->calendar(TestDataFixture::CALENDAR_2_ID);
        self::assertSame($revision + 1, $stored->revision);
        self::assertSame(2028, $stored->year);
        // The year change moved the dated item — the cascade is the browser's.
        self::assertSame('2028-06-01', self::dig($stored->content, 'events', 'Ev1tAb2cDe3fGh4i', 'date'));
        // Without a bleed nothing reaches past the trim any more.
        self::assertEquals(0, self::dig((array) json_decode((string) json_encode($stored->layout), true), 'boxes', 'head', 'x'));
        self::assertFalse($stored->print['bleed']);

        // The same call again changes nothing and saves nothing.
        $again = $this->ok('manage_calendar', ['action' => 'configure', 'calendarId' => TestDataFixture::CALENDAR_2_ID, 'year' => 2028, 'features' => ['events' => true]]);
        self::assertSame([], $again['changed']);
        self::assertSame([], $again['recorded']);
        self::assertSame($revision + 1, $this->calendar(TestDataFixture::CALENDAR_2_ID)->revision);
    }

    public function testChangingTheTypeRebasesTheLayoutToTheTwoSidedPreset(): void
    {
        $configured = $this->ok('manage_calendar', ['action' => 'configure', 'calendarId' => TestDataFixture::CALENDAR_2_ID, 'type' => 'fortnight']);

        self::assertSame('fortnight', $configured['type']);
        self::assertSame('twoSided', $configured['preset']);
        self::assertArrayHasKey('cal2', self::arr(self::dig($configured, 'layout', 'boxes')));
        self::assertStringContainsString('reset', self::text($configured['notes']));

        $stored = $this->calendar(TestDataFixture::CALENDAR_2_ID);
        self::assertSame(CalendarType::Fortnight, $stored->type);
        self::assertSame('twoSided', $stored->layoutPreset);

        // 14 days per page: cover + 27 pages + back.
        self::assertCount(29, self::arr($this->ok('get_calendar', ['calendarId' => TestDataFixture::CALENDAR_2_ID])['pages']));
    }

    public function testInvalidSettingsAreRefusedWithAReasonAndStoreNothing(): void
    {
        $calendar = $this->calendar(TestDataFixture::CALENDAR_1_ID);
        $revision = $calendar->revision;

        $cases = [
            'nothing_to_do' => [],
            'invalid_year' => ['year' => 1999],
            'invalid_name' => ['name' => '   '],
            'unknown_feature' => ['features' => ['events' => true, 'pocasi' => true]],
            'invalid_print' => ['print' => ['bleedMm' => 25]],
        ];

        foreach ($cases as $reason => $arguments) {
            self::assertStringEndsWith(
                '(' . $reason . ')',
                $this->refused('manage_calendar', ['action' => 'configure', 'calendarId' => TestDataFixture::CALENDAR_1_ID] + $arguments),
                $reason,
            );
        }

        self::assertStringEndsWith('(invalid_name)', $this->refused('manage_calendar', ['action' => 'configure', 'calendarId' => TestDataFixture::CALENDAR_1_ID, 'name' => str_repeat('ž', 61)]));
        self::assertStringEndsWith('(calendar_not_found)', $this->refused('manage_calendar', ['action' => 'configure', 'calendarId' => TestDataFixture::CALENDAR_3_ID, 'name' => 'Cizí']));

        $stored = $this->calendar(TestDataFixture::CALENDAR_1_ID);
        self::assertSame($revision, $stored->revision);
        self::assertSame($calendar->name, $stored->name);
        self::assertSame([], $stored->features, 'The valid switch in front of the unknown one was not stored either.');
    }

    public function testFinishNeedsALayoutAndThenMarksTheCalendarDone(): void
    {
        self::assertStringEndsWith('(no_layout)', $this->refused('manage_calendar', ['action' => 'finish', 'calendarId' => TestDataFixture::CALENDAR_1_ID]));
        self::assertFalse($this->calendar(TestDataFixture::CALENDAR_1_ID)->finished);

        $this->ok('set_calendar_layout', ['calendarId' => TestDataFixture::CALENDAR_1_ID, 'preset' => 'photoLeft']);
        $finished = $this->ok('manage_calendar', ['action' => 'finish', 'calendarId' => TestDataFixture::CALENDAR_1_ID]);

        self::assertTrue($finished['finished']);
        self::assertSame(5, $finished['step'], 'A finished calendar opens in the preview.');
        self::assertSame(['Dokončil kalendář'], array_column(self::arr($finished['recorded']), 'what'));

        $stored = $this->calendar(TestDataFixture::CALENDAR_1_ID);
        self::assertTrue($stored->finished);
        self::assertSame(5, $stored->step);

        $again = $this->ok('manage_calendar', ['action' => 'finish', 'calendarId' => TestDataFixture::CALENDAR_1_ID]);
        self::assertSame([], $again['changed']);
        self::assertSame(['Uložil vzhled kalendáře'], array_column(self::arr($again['recorded']), 'what'));
    }

    public function testTheClientLinkIsHandedToADesignerAndCanBeRegenerated(): void
    {
        $link = $this->ok('manage_calendar', ['action' => 'client_link', 'calendarId' => TestDataFixture::CALENDAR_2_ID]);

        self::assertStringEndsWith('/k/' . TestDataFixture::CALENDAR_2_TOKEN, self::str($link['clientUrl']));
        self::assertSame([], $link['changed']);
        self::assertSame(['Zkopíroval odkaz pro klienta'], array_column(self::arr($link['recorded']), 'what'));

        $regenerated = $this->ok('manage_calendar', ['action' => 'client_link', 'calendarId' => TestDataFixture::CALENDAR_2_ID, 'regenerate' => true]);
        $token = $this->calendar(TestDataFixture::CALENDAR_2_ID)->clientToken;

        self::assertNotSame(TestDataFixture::CALENDAR_2_TOKEN, $token);
        self::assertStringEndsWith('/k/' . $token, self::str($regenerated['clientUrl']));
        self::assertSame(['Vygeneroval nový odkaz pro klienta'], array_column(self::arr($regenerated['recorded']), 'what'));
    }

    public function testImportLayoutTakesOverASiblingsLayoutWithItsPictures(): void
    {
        // The source's logo is a picture of the SOURCE calendar.
        $logo = $this->ok('upload_calendar_asset', ['calendarId' => TestDataFixture::CALENDAR_2_ID, 'kind' => 'logo', 'fileBase64' => base64_encode(self::SVG), 'filename' => 'logo.svg']);
        $this->ok('set_calendar_layout', ['calendarId' => TestDataFixture::CALENDAR_2_ID, 'styles' => ['logo' => ['asset' => $logo['assetId']], 'num' => ['size' => 33]]]);

        $imported = $this->ok('manage_calendar', ['action' => 'import_layout', 'calendarId' => TestDataFixture::CALENDAR_1_ID, 'fromCalendarId' => TestDataFixture::CALENDAR_2_ID]);

        self::assertSame('photoLeft', $imported['preset']);
        self::assertEquals(33, self::dig($imported, 'layout', 'styles', 'num', 'size'));
        self::assertContains('layout', self::arr($imported['changed']));
        self::assertEqualsCanonicalizing(
            ['Načetl layout z „Obecní kalendář“', 'Upravil vzhled a nastavení'],
            array_column(self::arr($imported['recorded']), 'what'),
        );

        // The picture was COPIED: a new asset of the target calendar.
        $copiedLogo = self::str(self::dig($imported, 'layout', 'styles', 'logo', 'asset'));
        self::assertNotSame($logo['assetId'], $copiedLogo);
        $asset = self::getContainer()->get(CalendarAssetRepository::class)->get(Uuid::fromString($copiedLogo));
        self::assertSame(TestDataFixture::CALENDAR_1_ID, $asset->calendar->id->toString());
        self::assertTrue(self::getContainer()->get(Filesystem::class)->fileExists($asset->path));

        self::assertNotNull($this->calendar(TestDataFixture::CALENDAR_1_ID)->layout);
    }

    public function testImportLayoutRefusals(): void
    {
        $revision = $this->calendar(TestDataFixture::CALENDAR_2_ID)->revision;

        $cases = [
            'not_a_sibling' => [TestDataFixture::CALENDAR_2_ID, TestDataFixture::CALENDAR_2_ID],
            'source_has_no_layout' => [TestDataFixture::CALENDAR_2_ID, TestDataFixture::CALENDAR_1_ID],
            // A calendar of a project the caller cannot see does not exist.
            'calendar_not_found' => [TestDataFixture::CALENDAR_2_ID, TestDataFixture::CALENDAR_3_ID],
            'missing_argument' => [TestDataFixture::CALENDAR_2_ID, ''],
        ];

        foreach ($cases as $reason => [$target, $source]) {
            self::assertStringEndsWith(
                '(' . $reason . ')',
                $this->refused('manage_calendar', ['action' => 'import_layout', 'calendarId' => $target, 'fromCalendarId' => $source]),
                $reason,
            );
        }

        $this->ok('manage_calendar', ['action' => 'configure', 'calendarId' => TestDataFixture::CALENDAR_1_ID, 'type' => 'fortnight']);
        self::assertStringEndsWith('(layout_type_mismatch)', $this->refused('manage_calendar', ['action' => 'import_layout', 'calendarId' => TestDataFixture::CALENDAR_1_ID, 'fromCalendarId' => TestDataFixture::CALENDAR_2_ID]));

        self::assertSame($revision, $this->calendar(TestDataFixture::CALENDAR_2_ID)->revision);
        self::assertNull($this->calendar(TestDataFixture::CALENDAR_1_ID)->layout);
    }

    // -----------------------------------------------------------------
    // set_calendar_layout
    // -----------------------------------------------------------------

    public function testAPresetGivesACalendarItsLayoutWithoutABrowser(): void
    {
        $revision = $this->calendar(TestDataFixture::CALENDAR_1_ID)->revision;

        $laidOut = $this->ok('set_calendar_layout', ['calendarId' => TestDataFixture::CALENDAR_1_ID, 'preset' => 'photoLeft']);

        self::assertSame('photoLeft', $laidOut['preset']);
        self::assertSame(3, $laidOut['step']);
        self::assertEqualsCanonicalizing(['preset', 'layout', 'step'], self::arr($laidOut['changed']));
        self::assertSame(['Upravil vzhled a nastavení'], array_column(self::arr($laidOut['recorded']), 'what'));

        $layout = self::arr($laidOut['layout']);
        self::assertSame(1, $layout['v']);
        self::assertSame(['qr', 'logo', 'cap', 'wk', 'month', 'head', 'cal', 'photo'], $layout['order']);
        // Fotka vlevo: the photo starts in the left bleed, the Kalendárium ends in the right one.
        self::assertEquals(-5, self::dig($layout, 'boxes', 'photo', 'x'));
        self::assertEquals(315, self::number(self::dig($layout, 'boxes', 'cal', 'x')) + self::number(self::dig($layout, 'boxes', 'cal', 'w')));
        self::assertSame('end', self::dig($layout, 'styles', 'cal', 'numberSide'));
        self::assertSame(['waste', 'moon'], self::dig($layout, 'styles', 'icons', 'order'));

        $stored = $this->calendar(TestDataFixture::CALENDAR_1_ID);
        self::assertSame($revision + 1, $stored->revision);
        self::assertSame('photoLeft', $stored->layoutPreset);
        self::assertNotNull($stored->layout);
        // Maps keyed by data stay JSON objects in storage (`offsets: {}`), or
        // the browser would read a list.
        self::assertStringContainsString('"offsets":{}', (string) json_encode($stored->layout));

        // Switching to the other preset mirrors the layout and keeps its styling.
        $this->ok('set_calendar_layout', ['calendarId' => TestDataFixture::CALENDAR_1_ID, 'styles' => ['num' => ['size' => 31]]]);
        $mirrored = $this->ok('set_calendar_layout', ['calendarId' => TestDataFixture::CALENDAR_1_ID, 'preset' => 'photoRight']);
        self::assertSame('photoRight', $mirrored['preset']);
        self::assertEquals(31, self::dig($mirrored, 'layout', 'styles', 'num', 'size'));
        self::assertSame('start', self::dig($mirrored, 'layout', 'styles', 'cal', 'numberSide'));
        self::assertEquals(-5, self::dig($mirrored, 'layout', 'boxes', 'cal', 'x'));
        self::assertStringContainsString('mirrored', self::text($mirrored['notes']));

        // reset: true throws the styling away.
        $reset = $this->ok('set_calendar_layout', ['calendarId' => TestDataFixture::CALENDAR_1_ID, 'preset' => 'photoRight', 'reset' => true]);
        self::assertEquals(28, self::dig($reset, 'layout', 'styles', 'num', 'size'));
    }

    public function testBoxesOrderAndStylesArePatchedAndClamped(): void
    {
        $patched = $this->ok('set_calendar_layout', [
            'calendarId' => TestDataFixture::CALENDAR_2_ID,
            'boxes' => ['cal' => ['x' => 200.04, 'w' => 500], 'logo' => ['hidden' => true], 'photo' => ['w' => 2]],
            'order' => ['logo', 'qr', 'cap', 'wk', 'month', 'head', 'cal', 'photo'],
            'styles' => [
                'num' => ['size' => 80, 'color' => '#1a2b3c'],
                'month' => ['upper' => false, 'align' => 'start'],
                'lines' => ['style' => 'dot', 'width' => 0.5],
                'icons' => ['order' => ['moon', 'waste', 'ev']],
                'offsets' => ['num' => ['x' => 1.5, 'y' => 0]],
                'waste' => ['kinds' => ['kom' => ['color' => '#00aa00']]],
                'bg' => ['gradient' => ['on' => true, 'stops' => [['color' => '#ffffff', 'opacity' => 100, 'pos' => 0], ['color' => '#eeeeee', 'opacity' => 100, 'pos' => 100]]]],
            ],
        ]);

        $layout = self::arr($patched['layout']);
        self::assertEquals(200, self::dig($layout, 'boxes', 'cal', 'x'), 'Geometry is rounded to 0.1 mm.');
        self::assertEquals(315, self::number(self::dig($layout, 'boxes', 'cal', 'x')) + self::number(self::dig($layout, 'boxes', 'cal', 'w')), 'A box ends at the bleed edge at the latest.');
        self::assertTrue(self::dig($layout, 'boxes', 'logo', 'hidden'));
        self::assertEquals(6, self::dig($layout, 'boxes', 'photo', 'w'), 'Never narrower than the minimum box.');
        self::assertSame('logo', self::dig($layout, 'order', 0));

        self::assertEquals(60, self::dig($layout, 'styles', 'num', 'size'), 'A type size is clamped to its limit.');
        self::assertSame('#1A2B3C', self::dig($layout, 'styles', 'num', 'color'));
        self::assertEquals(800, self::dig($layout, 'styles', 'num', 'weight'), 'A key that was not named keeps its value.');
        self::assertFalse(self::dig($layout, 'styles', 'month', 'upper'));
        self::assertSame('dot', self::dig($layout, 'styles', 'lines', 'style'));
        self::assertSame(['moon', 'waste', 'ev'], self::dig($layout, 'styles', 'icons', 'order'));
        self::assertEquals(['x' => 1.5, 'y' => 0], self::dig($layout, 'styles', 'offsets', 'num'));
        self::assertSame('#00AA00', self::dig($layout, 'styles', 'waste', 'kinds', 'kom', 'color'));
        self::assertTrue(self::dig($layout, 'styles', 'bg', 'gradient', 'on'));
        self::assertSame('#EEEEEE', self::dig($layout, 'styles', 'bg', 'gradient', 'stops', 1, 'color'));

        $notes = self::text($patched['notes']);
        self::assertStringContainsString('styles.num.size: 80 → 60', $notes);
        self::assertStringContainsString('boxes.photo.w: 2 → 6', $notes);
        self::assertStringContainsString('boxes.cal.w: 500', $notes);

        self::assertSame(['layout'], $patched['changed']);

        // Removing an entry of a data-keyed map: null.
        $removed = $this->ok('set_calendar_layout', ['calendarId' => TestDataFixture::CALENDAR_2_ID, 'styles' => ['offsets' => ['num' => null]], 'boxes' => ['logo' => ['hidden' => false]]]);
        self::assertSame([], self::dig($removed, 'layout', 'styles', 'offsets'));
        self::assertArrayNotHasKey('hidden', self::arr(self::dig($removed, 'layout', 'boxes', 'logo')));
    }

    public function testAnUnknownKeyOrAWrongValueIsRefusedByNameAndStoresNothing(): void
    {
        $revision = $this->calendar(TestDataFixture::CALENDAR_2_ID)->revision;
        $foreignAsset = Uuid::uuid7()->toString();

        $cases = [
            ['unknown_style_key', 'styles.num.fontSize', ['styles' => ['lines' => ['width' => 2], 'num' => ['fontSize' => 30]]]],
            ['unknown_style_key', 'styles.title', ['styles' => ['title' => ['size' => 30]]]],
            ['unknown_box', '"sidebar"', ['boxes' => ['sidebar' => ['x' => 1]]]],
            ['invalid_box', 'boxes.cal.left', ['boxes' => ['cal' => ['left' => 1]]]],
            ['invalid_box', 'boxes.cal.x', ['boxes' => ['cal' => ['x' => 'vlevo']]]],
            ['not_hideable', 'photo', ['boxes' => ['photo' => ['hidden' => true]]]],
            ['not_hideable', 'captions', ['boxes' => ['cap' => ['hidden' => true]]]],
            ['invalid_order', 'every element exactly once', ['order' => ['photo', 'cal']]],
            ['invalid_color', 'styles.num.color', ['styles' => ['num' => ['color' => 'red']]]],
            ['invalid_color', 'styles.bg.gradient.stops[0].color', ['styles' => ['bg' => ['gradient' => ['stops' => [['color' => 'blue', 'opacity' => 100, 'pos' => 0], ['color' => '#FFFFFF', 'opacity' => 100, 'pos' => 100]]]]]]],
            ['unknown_font', 'styles.day.family', ['styles' => ['day' => ['family' => 'Comic Sans (Regular)']]]],
            ['invalid_style_value', 'styles.lines.style', ['styles' => ['lines' => ['style' => 'wavy']]]],
            ['invalid_style_value', 'styles.num.size', ['styles' => ['num' => ['size' => 'big']]]],
            ['invalid_style_value', 'styles.num', ['styles' => ['num' => 30]]],
            ['invalid_style_value', 'styles.icons.order', ['styles' => ['icons' => ['order' => ['moon', 'sun']]]]],
            ['preset_type_mismatch', 'twoSided', ['preset' => 'twoSided']],
            ['nothing_to_do', 'preset', []],
            // The server's own rule: a picture must be uploaded to THIS calendar.
            ['invalid_design', 'není nahraný', ['styles' => ['logo' => ['asset' => $foreignAsset]]]],
        ];

        foreach ($cases as [$reason, $names, $arguments]) {
            $refusal = $this->refused('set_calendar_layout', ['calendarId' => TestDataFixture::CALENDAR_2_ID] + $arguments);

            self::assertStringEndsWith('(' . $reason . ')', $refusal, $names);
            self::assertStringContainsString($names, $refusal);
        }

        self::assertSame($revision, $this->calendar(TestDataFixture::CALENDAR_2_ID)->revision, 'A refused call stores nothing — also when only part of it was wrong.');

        // Styling needs a layout.
        self::assertStringEndsWith('(no_layout)', $this->refused('set_calendar_layout', ['calendarId' => TestDataFixture::CALENDAR_1_ID, 'styles' => ['num' => ['size' => 30]]]));
        $foreignRevision = $this->calendar(TestDataFixture::CALENDAR_3_ID)->revision;
        self::assertStringEndsWith('(calendar_not_found)', $this->refused('set_calendar_layout', ['calendarId' => TestDataFixture::CALENDAR_3_ID, 'preset' => 'photoRight']));
        self::assertSame($foreignRevision, $this->calendar(TestDataFixture::CALENDAR_3_ID)->revision);
    }

    // -----------------------------------------------------------------
    // roles and scopes
    // -----------------------------------------------------------------

    /**
     * SHARED_USER can only VIEW PROJECT_1: `calendars:write` in hand, and
     * still a calendar CLIENT — no setting, no layout, no finish, no link.
     */
    public function testAReadOnlyMemberCannotTouchTheDesign(): void
    {
        $token = TestDataFixture::MCP_TOKEN_CALENDARS_SHARED_USER;
        $calendar = $this->calendar(TestDataFixture::CALENDAR_2_ID);
        $revision = $calendar->revision;

        $calls = [
            ['manage_calendar', ['action' => 'configure', 'calendarId' => TestDataFixture::CALENDAR_2_ID, 'name' => 'Přejmenováno']],
            ['manage_calendar', ['action' => 'finish', 'calendarId' => TestDataFixture::CALENDAR_2_ID]],
            ['manage_calendar', ['action' => 'client_link', 'calendarId' => TestDataFixture::CALENDAR_2_ID]],
            ['manage_calendar', ['action' => 'client_link', 'calendarId' => TestDataFixture::CALENDAR_2_ID, 'regenerate' => true]],
            ['manage_calendar', ['action' => 'import_layout', 'calendarId' => TestDataFixture::CALENDAR_1_ID, 'fromCalendarId' => TestDataFixture::CALENDAR_2_ID]],
            ['set_calendar_layout', ['calendarId' => TestDataFixture::CALENDAR_2_ID, 'styles' => ['num' => ['size' => 30]]]],
            ['set_calendar_layout', ['calendarId' => TestDataFixture::CALENDAR_1_ID, 'preset' => 'photoLeft']],
            ['upload_calendar_asset', ['calendarId' => TestDataFixture::CALENDAR_2_ID, 'kind' => 'logo', 'fileBase64' => base64_encode(self::SVG), 'filename' => 'logo.svg']],
        ];

        foreach ($calls as [$tool, $arguments]) {
            $refusal = $this->refused($tool, $arguments, $token);

            self::assertStringEndsWith('(designer_only)', $refusal, $tool . ' ' . json_encode($arguments));
            self::assertStringNotContainsString(TestDataFixture::CALENDAR_2_TOKEN, $refusal);
        }

        $stored = $this->calendar(TestDataFixture::CALENDAR_2_ID);
        self::assertSame($revision, $stored->revision);
        self::assertSame($calendar->name, $stored->name);
        self::assertSame(TestDataFixture::CALENDAR_2_TOKEN, $stored->clientToken);
        self::assertNull($this->calendar(TestDataFixture::CALENDAR_1_ID)->layout);

        // What a client MAY do: upload a photo and put it on a page.
        $photo = $this->ok('upload_calendar_asset', ['calendarId' => TestDataFixture::CALENDAR_2_ID, 'kind' => 'photo', 'fileBase64' => base64_encode(self::jpeg(640, 300)), 'filename' => 'tyden.jpg'], $token);
        $saved = $this->ok('update_calendar_content', ['calendarId' => TestDataFixture::CALENDAR_2_ID, 'operations' => [
            ['collection' => 'pagePhotos', 'id' => 'w02', 'value' => ['asset' => $photo['assetId'], 'zoom' => 1, 'px' => 0.5, 'py' => 0.5], 'label' => '2. týden'],
        ]], $token);
        self::assertSame('client', $saved['role']);
    }

    public function testAReadTokenIsRefusedEveryWriteToolAtTheGate(): void
    {
        $calls = [
            'manage_calendar' => ['action' => 'create', 'projectId' => TestDataFixture::PROJECT_1_ID],
            'set_calendar_layout' => ['calendarId' => TestDataFixture::CALENDAR_1_ID, 'preset' => 'photoLeft'],
            'upload_calendar_asset' => ['calendarId' => TestDataFixture::CALENDAR_1_ID, 'kind' => 'logo', 'fileBase64' => base64_encode(self::SVG), 'filename' => 'logo.svg'],
        ];
        $before = $this->calendarCount();

        foreach ($calls as $tool => $arguments) {
            $response = $this->raw($tool, $arguments, TestDataFixture::MCP_TOKEN_CALENDARS_READ);

            self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), $tool);
            self::assertStringContainsString('scope="calendars:write"', (string) $response->headers->get('WWW-Authenticate'), $tool);
        }

        self::assertSame($before, $this->calendarCount());
        self::assertNull($this->calendar(TestDataFixture::CALENDAR_1_ID)->layout);
    }

    // -----------------------------------------------------------------
    // upload_calendar_asset
    // -----------------------------------------------------------------

    public function testAnUploadedPhotoCanBeReferencedByAPage(): void
    {
        $revision = $this->calendar(TestDataFixture::CALENDAR_1_ID)->revision;

        $photo = $this->ok('upload_calendar_asset', [
            'calendarId' => TestDataFixture::CALENDAR_1_ID,
            'kind' => 'photo',
            'fileBase64' => 'data:image/jpeg;base64,' . chunk_split(base64_encode(self::jpeg(640, 300))),
            'filename' => 'C:\\fotky\\náves.JPG',
        ]);

        self::assertSame('photo', $photo['kind']);
        self::assertSame(640, $photo['width']);
        self::assertSame(300, $photo['height']);
        self::assertSame('image/jpeg', $photo['mime']);
        self::assertSame('náves.JPG', $photo['name']);
        self::assertStringContainsString('pagePhotos', self::str($photo['next']));

        $asset = self::getContainer()->get(CalendarAssetRepository::class)->get(Uuid::fromString(self::str($photo['assetId'])));
        self::assertSame(TestDataFixture::CALENDAR_1_ID, $asset->calendar->id->toString());
        self::assertSame(CalendarAuthorKind::Designer, $asset->uploadedByKind);
        self::assertTrue(self::getContainer()->get(Filesystem::class)->fileExists($asset->path));
        self::assertSame($revision, $this->calendar(TestDataFixture::CALENDAR_1_ID)->revision, 'Uploading never changes the calendar document.');

        $saved = $this->ok('update_calendar_content', ['calendarId' => TestDataFixture::CALENDAR_1_ID, 'operations' => [
            ['collection' => 'pagePhotos', 'id' => 'w01', 'value' => ['asset' => $photo['assetId'], 'zoom' => 1, 'px' => 0.5, 'py' => 0.5], 'label' => '1. týden'],
        ]]);
        self::assertSame(['Nahrál fotku — 1. týden'], array_column(self::arr($saved['recorded']), 'what'));

        $read = $this->ok('get_calendar', ['calendarId' => TestDataFixture::CALENDAR_1_ID]);
        self::assertSame('photo', self::dig($read, 'assets', self::str($photo['assetId']), 'kind'));
    }

    public function testUploadRefusals(): void
    {
        $base = ['calendarId' => TestDataFixture::CALENDAR_1_ID, 'filename' => 'soubor'];

        $cases = [
            // The web's own rule: a photo is a raster, judged by its bytes.
            'upload_refused' => ['kind' => 'photo', 'fileBase64' => base64_encode(self::SVG)],
            'invalid_file' => ['kind' => 'photo', 'fileBase64' => 'https://example.com/fotka.jpg'],
        ];

        foreach ($cases as $reason => $arguments) {
            self::assertStringEndsWith('(' . $reason . ')', $this->refused('upload_calendar_asset', $base + $arguments), $reason);
        }

        self::assertStringEndsWith('(invalid_file)', $this->refused('upload_calendar_asset', $base + ['kind' => 'logo', 'fileBase64' => '%%%']));
        self::assertStringEndsWith('(upload_refused)', $this->refused('upload_calendar_asset', $base + ['kind' => 'logo', 'fileBase64' => base64_encode('this is not a picture')]));
        self::assertStringEndsWith('(calendar_not_found)', $this->refused('upload_calendar_asset', ['calendarId' => TestDataFixture::CALENDAR_3_ID, 'filename' => 'a.svg', 'kind' => 'logo', 'fileBase64' => base64_encode(self::SVG)]));

        self::assertSame([], self::getContainer()->get(CalendarAssetRepository::class)->forCalendar(Uuid::fromString(TestDataFixture::CALENDAR_1_ID)));
    }

    /**
     * Content is complete over MCP: every one of the thirteen collections is
     * written through the one tool, pictures included, and a special page
     * that is switched on becomes a page of the calendar.
     */
    public function testEveryContentCollectionCanBeFilledAndSpecialPagesBecomePages(): void
    {
        $id = TestDataFixture::CALENDAR_2_ID;

        $this->ok('manage_calendar', ['action' => 'configure', 'calendarId' => $id, 'features' => [
            'events' => true, 'waste' => true, 'shifts' => true, 'specialPages' => true, 'qr' => true, 'timeChange' => true, 'seasons' => true,
        ]]);

        $upload = fn (string $kind, string $bytes, string $name): string => self::str($this->ok('upload_calendar_asset', ['calendarId' => $id, 'kind' => $kind, 'fileBase64' => base64_encode($bytes), 'filename' => $name])['assetId']);
        $photo = $upload('photo', self::jpeg(640, 300), 'foto.jpg');
        $icon = $upload('icon', self::SVG, 'ikona.svg');
        $qr = $upload('qr', self::SVG, 'qr.svg');
        $picture = $upload('image', self::jpeg(800, 200), 'hlavicka.jpg');
        $pageGraphic = $upload('page', self::jpeg(620, 290), 'mapa.jpg');

        $operations = [
            ['collection' => 'clubs', 'id' => 'Cl1tAb2cDe3fGh4i', 'value' => ['code' => 'SDH', 'name' => 'Sbor dobrovolných hasičů', 'pos' => 0]],
            ['collection' => 'events', 'id' => 'Ev1tAb2cDe3fGh4i', 'value' => ['date' => '2027-01-09', 'name' => 'Hasičský ples', 'club' => 'Cl1tAb2cDe3fGh4i', 'icon' => $icon], 'label' => '9. 1.'],
            ['collection' => 'names', 'id' => 'Nm1tAb2cDe3fGh4i', 'value' => ['date' => '2027-01-05', 'name' => 'Jarmila'], 'label' => '5. 1.'],
            ['collection' => 'wasteKinds', 'id' => 'bio', 'value' => ['builtin' => 'bio', 'name' => 'Bio odpad', 'on' => true, 'place' => '', 'mode' => 'rule', 'weekdays' => [2], 'interval' => '2e', 'from' => null, 'to' => null, 'dates' => [], 'pos' => 1]],
            ['collection' => 'wasteExtras', 'id' => 'Wx1tAb2cDe3fGh4i', 'value' => ['kind' => 'bio', 'date' => '2027-01-08'], 'label' => '8. 1.'],
            ['collection' => 'comments', 'id' => 'Cm1tAb2cDe3fGh4i', 'value' => ['date' => '2027-01-06', 'text' => 'Koncert začíná v 17 hodin.'], 'label' => '6. 1.'],
            ['collection' => 'pagePhotos', 'id' => 'w01', 'value' => ['asset' => $photo, 'zoom' => 1.2, 'px' => 0.5, 'py' => 0.4], 'label' => '1. týden'],
            ['collection' => 'pageCaptions', 'id' => 'w01', 'value' => ['text' => 'Náves v zimě'], 'label' => '1. týden'],
            ['collection' => 'pageImages', 'id' => 'w01', 'value' => ['head' => $picture, 'bg' => null], 'label' => '1. týden'],
            ['collection' => 'pageQr', 'id' => 'w01', 'value' => ['asset' => $qr], 'label' => '1. týden'],
            ['collection' => 'moonEdits', 'id' => 'Mo1tAb2cDe3fGh4i', 'value' => ['op' => 'add', 'date' => '2027-01-05', 'phase' => 2]],
            ['collection' => 'shiftOps', 'id' => 'Sh1tAb2cDe3fGh4i', 'value' => ['name' => 'Provoz A', 'shifts' => [['letter' => 'R', 'from' => '06:00', 'to' => '14:00'], ['letter' => 'O', 'from' => '14:00', 'to' => '22:00']], 'crews' => ['A', 'B', 'C', 'D'], 'pos' => 0]],
            ['collection' => 'specialPages', 'id' => 'intro', 'value' => ['kind' => 'intro', 'on' => true, 'layout' => 0, 'position' => 'front', 'nextYear' => false, 'name' => '', 'asset' => null, 'text' => 'Vážení spoluobčané…', 'fileName' => null, 'pos' => 0]],
            ['collection' => 'specialPages', 'id' => 'Sp1tAb2cDe3fGh4i', 'value' => ['kind' => 'image', 'on' => true, 'layout' => 0, 'position' => 'back', 'nextYear' => false, 'name' => 'Mapa obce', 'asset' => $pageGraphic, 'text' => null, 'fileName' => 'mapa.jpg', 'pos' => 4]],
        ];

        $saved = $this->ok('update_calendar_content', ['calendarId' => $id, 'operations' => $operations]);

        // Every operation was stored (the seeded waste kind is an update).
        self::assertCount(count($operations), self::arr($saved['results']));
        self::assertSame(['created' => 13, 'updated' => 1], array_count_values(array_map(static fn (mixed $status): string => is_string($status) ? $status : '', array_column(self::arr($saved['results']), 'status'))));

        $read = $this->ok('get_calendar', ['calendarId' => $id, 'historyLimit' => 0]);

        foreach (['clubs', 'events', 'names', 'wasteKinds', 'wasteExtras', 'comments', 'pagePhotos', 'pageCaptions', 'pageImages', 'pageQr', 'moonEdits', 'shiftOps', 'specialPages'] as $collection) {
            self::assertNotSame([], self::dig($read, 'content', $collection), $collection);
        }

        self::assertSame('designer', self::dig($read, 'content', 'comments', 'Cm1tAb2cDe3fGh4i', 'author'));
        self::assertCount(5, self::arr($read['assets']));

        // The special pages are pages now: the intro in front of the weeks,
        // the uploaded one behind them.
        $keys = array_column(self::arr($read['pages']), 'key');
        self::assertSame(['cover', 'sp:intro', 'pre'], array_slice($keys, 0, 3));
        self::assertSame(['w52', 'sp:Sp1tAb2cDe3fGh4i', 'back'], array_slice($keys, -3));
        self::assertSame('Mapa obce', self::dig($read, 'pages', count($keys) - 2, 'title'));

        // Switched off again, the page is gone and its content stays.
        $this->ok('manage_calendar', ['action' => 'configure', 'calendarId' => $id, 'features' => ['specialPages' => false]]);
        $without = $this->ok('get_calendar', ['calendarId' => $id, 'historyLimit' => 0]);
        self::assertNotContains('sp:intro', array_column(self::arr($without['pages']), 'key'));
        self::assertArrayHasKey('intro', self::arr(self::dig($without, 'content', 'specialPages')));
    }

    // -----------------------------------------------------------------
    // render_calendar_page
    // -----------------------------------------------------------------

    /**
     * The refusals that are decided before the renderer is asked — they need
     * no headless Chromium.
     */
    public function testRenderRefusesACalendarWithoutALayoutAndAnUnknownPage(): void
    {
        self::assertStringEndsWith('(no_layout)', $this->refused('render_calendar_page', ['calendarId' => TestDataFixture::CALENDAR_1_ID, 'page' => 'w01']));

        $unknown = $this->refused('render_calendar_page', ['calendarId' => TestDataFixture::CALENDAR_2_ID, 'page' => 'w99']);
        self::assertStringEndsWith('(unknown_page)', $unknown);
        self::assertStringContainsString('cover, pre, w01', $unknown);

        self::assertStringEndsWith('(calendar_not_found)', $this->refused('render_calendar_page', ['calendarId' => TestDataFixture::CALENDAR_3_ID, 'page' => 'w01']));
    }

    /**
     * The real picture: the calendar's own renderer inside Gotenberg's
     * Chromium. A read token is enough to look.
     */
    #[Group('gotenberg')]
    public function testRendersAWeekPageAndTheCoverAsAnImage(): void
    {
        $photo = $this->ok('upload_calendar_asset', ['calendarId' => TestDataFixture::CALENDAR_2_ID, 'kind' => 'photo', 'fileBase64' => base64_encode(self::jpeg(640, 300)), 'filename' => 'tyden.jpg']);
        $this->ok('update_calendar_content', ['calendarId' => TestDataFixture::CALENDAR_2_ID, 'operations' => [
            ['collection' => 'pagePhotos', 'id' => 'w01', 'value' => ['asset' => $photo['assetId'], 'zoom' => 1, 'px' => 0.5, 'py' => 0.5]],
        ]]);

        $week = $this->call('render_calendar_page', ['calendarId' => TestDataFixture::CALENDAR_2_ID, 'page' => 'w01'], TestDataFixture::MCP_TOKEN_CALENDARS_READ);
        self::assertFalse($week['isError'] ?? false, $week['content'][0]['text']);
        self::assertCount(2, $week['content']);

        $summary = self::decode($week['content'][0]['text']);
        self::assertSame('w01', $summary['page']);
        self::assertSame('week', $summary['kind']);
        self::assertSame('2027-01-04', $summary['from']);
        self::assertSame(1600, $summary['width']);
        self::assertSame(748, $summary['height']);
        self::assertEquals(['x' => 0, 'y' => 0, 'w' => 310, 'h' => 145], $summary['viewMm']);
        // A 640 px photo in a 202 mm frame is far below print resolution.
        self::assertStringContainsString('dpi', self::text($summary['warnings']));

        $image = $week['content'][1];
        self::assertSame('image', $image['type'] ?? null);
        self::assertSame('image/webp', $image['mimeType'] ?? null);
        $bytes = (string) base64_decode($image['data'] ?? '', true);
        self::assertSame('RIFF', substr($bytes, 0, 4));
        self::assertSame([1600, 748], array_slice((array) getimagesizefromstring($bytes), 0, 2));

        // With the bleed and the crop marks the picture shows more than the trim.
        $cover = $this->call('render_calendar_page', ['calendarId' => TestDataFixture::CALENDAR_2_ID, 'page' => 'cover', 'marks' => true]);
        self::assertFalse($cover['isError'] ?? false, $cover['content'][0]['text']);
        $coverSummary = self::decode($cover['content'][0]['text']);
        self::assertSame('cover', $coverSummary['kind']);
        self::assertLessThan(0, self::dig($coverSummary, 'viewMm', 'x'));
        self::assertSame('RIFF', substr((string) base64_decode(self::str(self::dig($cover, 'content', 1, 'data')), true), 0, 4));

        // A special page and the back page are pages like any other.
        $this->ok('manage_calendar', ['action' => 'configure', 'calendarId' => TestDataFixture::CALENDAR_2_ID, 'features' => ['specialPages' => true]]);
        $this->ok('update_calendar_content', ['calendarId' => TestDataFixture::CALENDAR_2_ID, 'operations' => [
            ['collection' => 'specialPages', 'id' => 'intro', 'value' => ['kind' => 'intro', 'on' => true, 'layout' => 0, 'position' => 'front', 'nextYear' => false, 'name' => '', 'asset' => null, 'text' => 'Vážení spoluobčané…', 'fileName' => null, 'pos' => 0]],
        ]]);

        foreach (['sp:intro' => 'special', 'back' => 'back'] as $key => $kind) {
            $page = $this->call('render_calendar_page', ['calendarId' => TestDataFixture::CALENDAR_2_ID, 'page' => $key]);
            self::assertFalse($page['isError'] ?? false, $page['content'][0]['text']);
            self::assertSame($kind, self::decode($page['content'][0]['text'])['kind']);
            self::assertSame('RIFF', substr((string) base64_decode(self::str(self::dig($page, 'content', 1, 'data')), true), 0, 4));
        }
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private function calendarCount(): int
    {
        $this->browser();

        return (int) self::number(self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM calendar'));
    }

    private static function jpeg(int $width, int $height): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('#7A9E7E'));
        $image->setImageFormat('jpeg');

        return $image->getImageBlob();
    }
}
