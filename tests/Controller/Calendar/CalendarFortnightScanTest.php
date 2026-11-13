<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Calendar;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\AiScan\AiScanKind;

/**
 * AI scan for a 14-day calendar (`type: fortnight`, the one layout
 * `twoSided`): who is handed its build, what the build is told, a candidate
 * with second frames, accept, „an element not found keeps its setting“ for
 * both frames, restore — and the note when the page and the calendar
 * disagree.
 *
 * @covers \WBoost\Web\Services\AiScan\ScannerApi
 * @covers \WBoost\Web\Services\AiScan\AiScanDefinition
 * @covers \WBoost\Web\Services\AiScan\Calendar\CalendarCandidate
 * @covers \WBoost\Web\Services\AiScan\Calendar\CalendarScanProposal
 * @covers \WBoost\Web\MessageHandler\AiScan\AcceptCalendarScanHandler
 * @covers \WBoost\Web\MessageHandler\AiScan\RestoreCalendarScanHandler
 */
final class CalendarFortnightScanTest extends WebTestCase
{
    use AiScanTesting;

    private const string PROJECT = TestDataFixture::PROJECT_1_ID;
    /** Made a 14-day calendar without a layout in setUp(). */
    private const string FORTNIGHT = TestDataFixture::CALENDAR_1_ID;
    private const string WEEK = TestDataFixture::CALENDAR_2_ID;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::entitle(self::PROJECT);
        self::switchOn();
        self::connection()->executeStatement("UPDATE calendar SET type = 'fortnight', layout = NULL, layout_preset = NULL WHERE id = :id", ['id' => self::FORTNIGHT]);
    }

    public function testOnlyAScannerThatKnowsTheTwoColumnLayoutIsHandedAFourteenDayCalendar(): void
    {
        $fortnight = self::start(self::PROJECT, kind: AiScanKind::Calendar, calendarId: self::FORTNIGHT)->toString();
        self::passIntake($this->client, $fortnight);

        // The image that built calendars before 14-day scans existed: `mm`, no `mm14`.
        $old = array_values(array_diff(self::instruments(), ['mm14']));
        self::assertContains('mm', $old);
        $answer = self::scanner($this->client, 'POST', 'claim', ['lane' => 'build', 'scannerVersion' => 'old-1', 'instruments' => $old, 'capacity' => 1]);

        self::assertSame(200, $answer['status']);
        self::assertNull($answer['json']['job']);
        self::assertSame('queued', self::text($fortnight, 'status'));

        // …while it still builds a 7-day calendar's scan, although that one came later.
        $week = self::start(self::PROJECT, kind: AiScanKind::Calendar, calendarId: self::WEEK)->toString();
        self::passIntake($this->client, $week);
        $answer = self::scanner($this->client, 'POST', 'claim', ['lane' => 'build', 'scannerVersion' => 'old-1', 'instruments' => $old, 'capacity' => 1]);
        self::assertSame($week, self::dig($answer['json'], 'job', 'id'));
        self::assertSame('week', self::dig($answer['json'], 'job', 'context', 'calendar', 'type'));

        // (one build at a time: the 7-day scan's run is taken out of the way)
        self::connection()->executeStatement("UPDATE ai_scan SET status = 'cancelled', lease_expires_at = NULL WHERE id = :id", ['id' => $week]);

        // The current image takes the 14-day one and is told what it builds on.
        $build = self::claim($this->client, 'build');
        self::assertSame($fortnight, $build['job']['id']);
        $calendar = self::dig($build['job'], 'context', 'calendar');
        self::assertIsArray($calendar);
        self::assertTrue($calendar['supported']);
        self::assertSame('fortnight', $calendar['type']);
        self::assertSame(['twoSided'], $calendar['presets']);
        self::assertFalse($calendar['hasLayout']);
        self::assertSame('twoSided', self::dig($calendar, 'defaultLayout', 'preset'));
        self::assertIsArray(self::dig($calendar, 'defaultLayout', 'layout', 'boxes', 'cal2'));
        self::assertIsArray(self::dig($calendar, 'defaultLayout', 'layout', 'boxes', 'month2'));
        // Two weeks, none with a public holiday.
        self::assertSame('2027-01-11', self::dig($calendar, 'samplePage', 'from'));
        self::assertSame('2027-01-24', self::dig($calendar, 'samplePage', 'to'));

        // The definition names the variant, its instrument and its files.
        $files = self::dig($build['job'], 'definition', 'files');
        self::assertIsArray($files);
        self::assertArrayHasKey('system.calendar.fortnight.md', $files);
        self::assertIsString($files['definition.json']);
        self::assertSame(['mm14'], self::dig(json_decode($files['definition.json'], true), 'kinds', 'calendar', 'variants', 'fortnight', 'requiresInstruments'));
    }

    public function testACandidateCarriesSecondFramesAndAPresetOfTheOtherTypeIsAnAnswer(): void
    {
        $scanId = self::start(self::PROJECT, kind: AiScanKind::Calendar, calendarId: self::FORTNIGHT)->toString();
        self::passIntake($this->client, $scanId);
        $build = self::claim($this->client, 'build');

        $wrong = self::scanner($this->client, 'POST', $scanId . '/render', ['run' => $build['run'], 'seq' => 1, 'commands' => [['op' => 'preset', 'preset' => 'photoLeft']]], $build['lease']);
        self::assertSame(200, $wrong['status']);
        self::assertNull($wrong['json']['candidate']);
        self::assertSame('preset_type_mismatch', self::dig($wrong['json'], 'issues', 0, 'code'));

        $render = self::scanner($this->client, 'POST', $scanId . '/render', ['run' => $build['run'], 'seq' => 2, 'commands' => self::fortnightCommands()], $build['lease']);

        self::assertSame(200, $render['status'], $render['raw']);
        self::assertSame(1, $render['json']['candidate']);
        self::assertSame('twoSided', $render['json']['preset']);
        self::assertSame(['x' => 236, 'y' => 31, 'w' => 79, 'h' => 107], self::dig($render['json'], 'layout', 'boxes', 'cal2'));
        self::assertSame(190, self::dig($render['json'], 'layout', 'boxes', 'month2', 'x'));
        self::assertSame('2027-01-24', self::dig($render['json'], 'page', 'to'));

        // Without a preset command the candidate starts from the calendar's one layout.
        $bare = self::scanner($this->client, 'POST', $scanId . '/render', ['run' => $build['run'], 'seq' => 3, 'commands' => [['op' => 'layout', 'boxes' => ['cal2' => ['y' => 40]]]]], $build['lease']);
        self::assertSame(2, $bare['json']['candidate'], $bare['raw']);
        self::assertSame('twoSided', $bare['json']['preset']);

        // Nothing was saved.
        self::assertNull(self::connection()->fetchOne('SELECT layout FROM calendar WHERE id = :id', ['id' => self::FORTNIGHT]));
    }

    public function testAcceptSavesTheTwoSidedLayoutAndRestoreBringsTheCalendarBack(): void
    {
        $scanId = $this->ready(['notes' => [['code' => 'week_page_on_fortnight']]]);
        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);

        $this->client->request('GET', '/ai-scan/' . $scanId . '/proposal');
        $proposal = self::dig(self::answer($this->client), 'proposal');
        self::assertSame('twoSided', self::dig($proposal, 'preset'));
        self::assertSame([['code' => 'week_page_on_fortnight']], self::dig($proposal, 'notes'));
        self::assertSame(236, self::dig($proposal, 'layout', 'boxes', 'cal2', 'x'));

        $answer = $this->post($scanId, 'accept', ['features' => ['events']]);

        self::assertTrue($answer['ok'], (string) json_encode($answer));
        self::assertTrue($answer['created']);
        self::assertSame(['events'], $answer['features']);

        $after = self::state();
        self::assertSame('fortnight', $after['type']);
        self::assertSame('twoSided', $after['preset']);
        self::assertSame('scan', $after['origin']);
        self::assertEquals(['x' => 236, 'y' => 31, 'w' => 79, 'h' => 107], self::dig($after['layout'], 'boxes', 'cal2'));
        self::assertEquals(['x' => -5, 'y' => 31, 'w' => 79, 'h' => 107], self::dig($after['layout'], 'boxes', 'cal'));
        self::assertSame('#1F3A5F', self::dig($after['layout'], 'styles', 'head', 'bg', 'color'));

        // The calendar had no layout: restore takes it back to "none".
        $restored = $this->post($scanId, 'restore');
        self::assertTrue($restored['ok'], (string) json_encode($restored));
        self::assertTrue($restored['restored']);
        $back = self::state();
        self::assertSame([], $back['layout']);
        self::assertSame('', $back['preset']);
        self::assertSame('', $back['origin']);
        self::assertSame('fortnight', $back['type']);
    }

    public function testAnElementThePageDoesNotShowKeepsBothOfItsFrames(): void
    {
        // The calendar gets a layout first (the first scan's), with the month frames moved.
        $first = $this->ready();
        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);
        self::assertTrue($this->post($first, 'accept')['ok']);
        $current = self::state()['layout'];
        self::assertSame(190, self::dig($current, 'boxes', 'month2', 'x'));

        // The second scan does not find the month line and proposes the default frames for it.
        $commands = self::fortnightCommands(month: false);
        $second = $this->ready(['recognised' => [['element' => 'cal', 'state' => 'found'], ['element' => 'month', 'state' => 'absent']]], $commands);

        $this->client->request('GET', '/ai-scan/' . $second . '/proposal');
        $proposal = self::dig(self::answer($this->client), 'proposal');
        self::assertEquals(self::dig($current, 'boxes', 'month'), self::dig($proposal, 'layout', 'boxes', 'month'));
        self::assertEquals(self::dig($current, 'boxes', 'month2'), self::dig($proposal, 'layout', 'boxes', 'month2'), 'the second frame is kept with the first');
        // …while the scan itself proposed the default frame.
        $layoutJson = self::dig(self::decoded(self::text($second, 'proposal')), 'document', 'layoutJson');
        self::assertIsString($layoutJson);
        $own = json_decode($layoutJson, true);
        self::assertSame(184, self::dig($own, 'boxes', 'month2', 'x'));

        self::assertTrue($this->post($second, 'accept')['ok']);
        self::assertEquals(self::dig($current, 'boxes', 'month2'), self::dig(self::state()['layout'], 'boxes', 'month2'));

        // Restore of the second scan: the first scan's layout again, still marked as scanned.
        self::assertTrue($this->post($second, 'restore')['restored']);
        self::assertEquals($current, self::state()['layout']);
        self::assertSame('scan', self::state()['origin']);
    }

    public function testAProposalBuiltForFourteenDaysIsNotAppliedToACalendarThatBecameASevenDayOne(): void
    {
        $scanId = $this->ready();
        TestingLogin::logInAsUser($this->client, TestDataFixture::USER_1_EMAIL);
        self::connection()->executeStatement("UPDATE calendar SET type = 'week' WHERE id = :id", ['id' => self::FORTNIGHT]);

        $answer = $this->post($scanId, 'accept');

        self::assertFalse($answer['ok']);
        self::assertSame('wrong_state', $answer['code']);
        self::assertSame([], self::state()['layout']);
        self::assertSame('ready', self::text($scanId, 'status'));
    }

    // ---- helpers ------------------------------------------------------------------

    /**
     * A candidate of the two-sided layout: both week columns 10 mm narrower
     * and 4 mm lower than the default, the photo wider, the month frames
     * moved inwards (`$month = false`: the month frames stay the default ones).
     *
     * @return list<array<string, mixed>>
     */
    private static function fortnightCommands(bool $month = true): array
    {
        $boxes = [
            'cal' => ['x' => -5, 'y' => 31, 'w' => 79, 'h' => 107],
            'cal2' => ['x' => 236, 'y' => 31, 'w' => 79, 'h' => 107],
            'photo' => ['x' => 74, 'y' => 31, 'w' => 162, 'h' => 107],
        ];

        if ($month) {
            $boxes['month'] = ['x' => 0, 'y' => 12.5, 'w' => 120, 'h' => 14.5];
            $boxes['month2'] = ['x' => 190, 'y' => 12.5, 'w' => 120, 'h' => 14.5];
        }

        return [
            ['op' => 'preset', 'preset' => 'twoSided'],
            ['op' => 'configure', 'features' => ['events' => true]],
            ['op' => 'layout', 'boxes' => $boxes, 'styles' => ['head' => ['bg' => ['color' => '#1F3A5F']]]],
        ];
    }

    /**
     * Upload → intake → build → one two-sided candidate → `ready`.
     *
     * @param array<string, mixed> $report overrides of the calendar report
     * @param null|list<array<string, mixed>> $commands
     */
    private function ready(array $report = [], null|array $commands = null): string
    {
        $scanId = self::start(self::PROJECT, kind: AiScanKind::Calendar, calendarId: self::FORTNIGHT)->toString();
        self::passIntake($this->client, $scanId);
        $build = self::claim($this->client, 'build');
        $render = self::scanner($this->client, 'POST', $scanId . '/render', ['run' => $build['run'], 'seq' => 1, 'commands' => $commands ?? self::fortnightCommands()], $build['lease']);
        self::assertSame(1, $render['json']['candidate'] ?? null, $render['raw']);

        $finish = self::scanner($this->client, 'POST', $scanId . '/finish', [
            'run' => $build['run'], 'outcome' => 'proposed', 'candidate' => 1,
            'report' => self::calendarReport(1, $report + [
                'type' => 'fortnight',
                'recognised' => [['element' => 'photo', 'state' => 'found'], ['element' => 'cal', 'state' => 'found']],
                'needsUpload' => [],
                'featuresOffered' => [['key' => 'events']],
                'notes' => [],
            ]),
            'usage' => self::usage(2),
        ], $build['lease']);
        self::assertSame('ready', $finish['json']['status'] ?? null, $finish['raw']);

        return $scanId;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function post(string $scanId, string $action, array $fields = []): array
    {
        $this->client->request('POST', sprintf('/calendar/%s/scan/%s/%s', self::FORTNIGHT, $scanId, $action), ['_token' => 'csrf-token'] + $fields, server: ['HTTP_ORIGIN' => 'http://localhost']);

        return self::answer($this->client);
    }

    /**
     * @return array<string, mixed>
     */
    private static function answer(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded, (string) $client->getResponse()->getContent());

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array{type: string, layout: array<mixed>, preset: string, origin: string}
     */
    private static function state(): array
    {
        $row = self::connection()->fetchAssociative('SELECT type, layout, layout_preset, layout_origin FROM calendar WHERE id = :id', ['id' => self::FORTNIGHT]);
        self::assertIsArray($row);
        $layout = is_string($row['layout']) ? json_decode($row['layout'], true) : null;

        return [
            'type' => is_string($row['type']) ? $row['type'] : '',
            'layout' => is_array($layout) ? $layout : [],
            'preset' => is_string($row['layout_preset']) ? $row['layout_preset'] : '',
            'origin' => is_string($row['layout_origin']) ? $row['layout_origin'] : '',
        ];
    }
}
