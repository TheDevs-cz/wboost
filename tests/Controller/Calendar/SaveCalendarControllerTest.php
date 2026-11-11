<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Calendar;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\Calendar\CalendarSavePayload;

/**
 * The designer's save endpoint: every design key round-trips, bad shapes
 * are refused whole, the answer is the stored document.
 *
 * @covers \WBoost\Web\Controller\Calendar\SaveCalendarController
 * @covers \WBoost\Web\Controller\Calendar\CalendarWorkspaceController
 * @covers \WBoost\Web\Services\Calendar\CalendarSaver
 */
final class SaveCalendarControllerTest extends WebTestCase
{
    use CalendarTesting;

    private const string WORKSPACE_URL = '/calendar/' . TestDataFixture::CALENDAR_1_ID;
    private const string SAVE_URL = self::WORKSPACE_URL . '/save';

    private const array PAGE = ['width' => 310, 'height' => 145, 'bandHeight' => 12.5, 'holes' => ['size' => 4.3, 'radius' => 0.6, 'pitch' => 8, 'top' => 4, 'margin' => 4.5]];
    private const array PRINT = ['bleed' => true, 'bleedMm' => 5, 'marks' => true];
    private const array FEATURES = [
        'captions' => true, 'weekNumbers' => true, 'moon' => true, 'timeChange' => false, 'seasons' => false,
        'waste' => true, 'events' => true, 'shifts' => false, 'specialPages' => false, 'qr' => false,
    ];

    public function testWorkspacePageCarriesTheBootstrapForADesigner(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $bootstrap = self::bootstrap($browser, self::WORKSPACE_URL);

        self::assertSelectorExists('.cal-ws[data-controller="calendar-workspace"][data-calendar-workspace-mode-value="designer"]');
        // Full-width app screen: no page header, sidebar forced to the rail.
        self::assertSelectorNotExists('header.head');
        self::assertSelectorExists('html.sb-rail[data-sb-forced="1"]');
        self::assertSelectorExists('.sb a[aria-current="page"][title="Kalendáře"]');
        self::assertStringContainsString("font-family: 'Rubik (Rubik Bold)'", (string) $browser->getResponse()->getContent());

        self::assertSame('designer', $bootstrap['mode']);
        self::assertSame([], $bootstrap['assets']);
        /** @var array<string, mixed> $env */
        $env = $bootstrap['env'];
        self::assertSame([
            'list' => '/project/' . TestDataFixture::PROJECT_1_ID . '/calendars',
            'save' => self::SAVE_URL,
            'asset' => self::WORKSPACE_URL . '/asset',
            'history' => self::WORKSPACE_URL . '/history',
            'export' => self::WORKSPACE_URL . '/export',
            'clientLink' => self::WORKSPACE_URL . '/client-link',
            'layoutImport' => self::WORKSPACE_URL . '/layout-import',
            'documentText' => self::WORKSPACE_URL . '/document-text',
        ], $env['urls']);
        self::assertSame('http://localhost/k/' . TestDataFixture::CALENDAR_1_TOKEN, $env['clientUrl']);
        self::assertSame([['id' => TestDataFixture::CALENDAR_2_ID, 'name' => 'Obecní kalendář', 'type' => 'week', 'hasLayout' => true]], $env['siblings']);
        self::assertSame(10_000_000, $env['maxFileSize']);

        // Empty maps are JSON objects, never `[]` (Twig / PHP would say `[]`).
        $raw = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('"page":{},"print":{},"features":{}', $raw);
        self::assertStringContainsString('"assets":{}', $raw);
    }

    public function testEveryDesignKeyRoundTrips(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        /** @var array<string, mixed> $layout */
        $layout = json_decode(TestDataFixture::CALENDAR_LAYOUT_JSON, true, 64, JSON_THROW_ON_ERROR);

        $design = [
            'name' => '  Kalendář   obce Bukovec ',
            'year' => 2028,
            'type' => 'week',
            'page' => self::PAGE,
            'print' => self::PRINT,
            'features' => self::FEATURES,
            'preset' => 'photoLeft',
            'layout' => json_decode(TestDataFixture::CALENDAR_LAYOUT_JSON, false, 64, JSON_THROW_ON_ERROR),
            'linkElements' => false,
            'step' => 3,
            'finished' => false,
        ];

        $answer = self::save($browser, self::SAVE_URL, ['design' => $design], $token);
        self::assertResponseIsSuccessful();
        self::assertSame(['calendar', 'assets', 'savedAt'], array_keys($answer));

        // …and a reload gives exactly the same document.
        $reloaded = self::bootstrap($browser, self::WORKSPACE_URL);
        self::assertEquals($answer['calendar'], $reloaded['calendar']);

        /** @var array<string, mixed> $calendar */
        $calendar = $reloaded['calendar'];
        self::assertSame('Kalendář obce Bukovec', $calendar['name']);
        self::assertSame(2028, $calendar['year']);
        self::assertSame(1, $calendar['revision']);
        self::assertEquals(self::PAGE, $calendar['page']);
        self::assertEquals(self::PRINT, $calendar['print']);
        self::assertEquals(self::FEATURES, $calendar['features']);
        self::assertSame('photoLeft', $calendar['preset']);
        self::assertFalse($calendar['linkElements']);
        self::assertSame(3, $calendar['step']);
        self::assertEquals($layout, $calendar['layout']);

        // The layout's empty and digit-keyed maps stay JSON objects.
        $raw = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('"offsets":{}', $raw);
        self::assertStringContainsString('"kinds":{}', $raw);
        self::assertStringContainsString('"iconCells":{"4":{"0":{', $raw);
        self::assertStringContainsString('"order":[]', $raw);

        self::assertSame([['Grafik', 'Upravil vzhled a nastavení'], ['Grafik', 'Vytvořil kalendář']], self::history(TestDataFixture::CALENDAR_1_ID));
    }

    public function testContentOpsAndFinish(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        $answer = self::save($browser, self::SAVE_URL, [
            'content' => [
                ['c' => 'events', 'id' => 'Ab3dEf9hIj', 'v' => self::event('Den dětí'), 'label' => '1. 6.'],
                ['c' => 'specialPages', 'id' => 'intro', 'v' => ['kind' => 'intro', 'on' => true, 'layout' => 1, 'position' => 'front', 'nextYear' => false, 'name' => '', 'asset' => null, 'text' => null, 'fileName' => null, 'pos' => 0]],
            ],
            'finish' => true,
        ], $token);

        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $calendar */
        $calendar = $answer['calendar'];
        self::assertTrue($calendar['finished']);
        self::assertSame(5, $calendar['step']);
        self::assertSame(self::event('Den dětí'), self::dig($calendar, 'content', 'events', 'Ab3dEf9hIj'));
        self::assertTrue(self::dig($calendar, 'content', 'specialPages', 'intro', 'on'));

        self::assertSame([
            ['Grafik', 'Dokončil kalendář'],
            ['Grafik', 'Upravil speciální stránky'],
            ['Grafik', 'Přidal akci „Den dětí“ — 1. 6.'],
            ['Grafik', 'Vytvořil kalendář'],
        ], self::history(TestDataFixture::CALENDAR_1_ID));

        // A second finish is "Uložil vzhled kalendáře"; deleting leaves `{}`.
        self::save($browser, self::SAVE_URL, ['content' => [['c' => 'events', 'id' => 'Ab3dEf9hIj', 'v' => null]], 'finish' => true], $token);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"events":{}', (string) $browser->getResponse()->getContent());
        self::assertSame(['Grafik', 'Uložil vzhled kalendáře'], self::history(TestDataFixture::CALENDAR_1_ID)[0]);
        self::assertSame(['Grafik', 'Odebral akci „Den dětí“'], self::history(TestDataFixture::CALENDAR_1_ID)[1]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideInvalidPayloads')]
    public function testInvalidShapesAreRefusedAndNothingIsStored(array $payload): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        // A valid op travels along — it must not be applied either.
        $payload = self::withOp($payload, ['c' => 'names', 'id' => 'validNameId', 'v' => ['date' => '2027-03-01', 'name' => 'Bedřich']]);

        $answer = self::save($browser, self::SAVE_URL, $payload, $token);

        self::assertResponseStatusCodeSame(422);
        self::assertIsString($answer['error']);
        self::assertNotSame('', $answer['error']);

        $calendar = self::calendar(TestDataFixture::CALENDAR_1_ID);
        self::assertSame(0, $calendar->revision);
        self::assertSame('Stolní kalendář 2027', $calendar->name);
        self::assertSame(2027, $calendar->year);
        self::assertNull($calendar->layout);
        self::assertArrayNotHasKey('names', $calendar->content);
        self::assertCount(1, self::history(TestDataFixture::CALENDAR_1_ID));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideInvalidPayloads(): iterable
    {
        /** @var array<string, mixed> $layout */
        $layout = json_decode(TestDataFixture::CALENDAR_LAYOUT_JSON, true, 64, JSON_THROW_ON_ERROR);

        yield 'empty name' => [['design' => ['name' => '   ']]];
        yield 'name too long' => [['design' => ['name' => str_repeat('a', 61)]]];
        yield 'year out of range' => [['design' => ['year' => 1999]]];
        yield 'year not an integer' => [['design' => ['year' => '2027']]];
        yield 'unknown type' => [['design' => ['type' => 'month']]];
        yield 'unknown design key' => [['design' => ['clientToken' => 'x']]];
        yield 'step out of range' => [['design' => ['step' => 6]]];
        yield 'preset of another type' => [['design' => ['preset' => 'twoSided']]];
        yield 'page with a missing key' => [['design' => ['page' => ['width' => 310, 'height' => 145]]]];
        yield 'page too small' => [['design' => ['page' => [...self::PAGE, 'width' => 10]]]];
        yield 'bleed too large' => [['design' => ['print' => [...self::PRINT, 'bleedMm' => 11]]]];
        yield 'features with an unknown key' => [['design' => ['features' => [...self::FEATURES, 'ai' => true]]]];
        yield 'layout of another version' => [['design' => ['layout' => [...$layout, 'v' => 2]]]];
        yield 'layout with an unknown box' => [['design' => ['layout' => [...$layout, 'boxes' => ['footer' => ['x' => 0, 'y' => 0, 'w' => 10, 'h' => 10]]]]]];
        yield 'layout box without a size' => [['design' => ['layout' => [...$layout, 'boxes' => ['photo' => ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 10]]]]]];
        yield 'layout order with a duplicate' => [['design' => ['layout' => [...$layout, 'order' => ['photo', 'photo']]]]];
        yield 'layout order with bg' => [['design' => ['layout' => [...$layout, 'order' => ['bg']]]]];
        yield 'layout style string too long' => [['design' => ['layout' => [...$layout, 'styles' => ['month' => ['family' => str_repeat('x', 256)]]]]]];
        yield 'layout with an unknown asset' => [['design' => ['layout' => [...$layout, 'styles' => ['logo' => ['asset' => '0196f000-0000-7000-8000-000000000001']]]]]];
        yield 'unknown collection' => [['content' => [['c' => 'secrets', 'id' => 'abcdefgh', 'v' => ['a' => 1]]]]];
        yield 'bad item id' => [['content' => [['c' => 'events', 'id' => 'no spaces!', 'v' => self::event('X')]]]];
        yield 'bad page key' => [['content' => [['c' => 'pageCaptions', 'id' => 'week5', 'v' => ['text' => 'x']]]]];
        yield 'extra item key' => [['content' => [['c' => 'events', 'id' => 'abcdefgh', 'v' => [...self::event('X'), 'html' => '<b>']]]]];
        yield 'date outside the year' => [['content' => [['c' => 'events', 'id' => 'abcdefgh', 'v' => self::event('X', '2028-02-01')]]]];
        yield 'date that does not exist' => [['content' => [['c' => 'events', 'id' => 'abcdefgh', 'v' => self::event('X', '2027-02-30')]]]];
        yield 'caption too long' => [['content' => [['c' => 'pageCaptions', 'id' => 'w05', 'v' => ['text' => str_repeat('a', 81)]]]]];
        yield 'photo with an unknown asset' => [['content' => [['c' => 'pagePhotos', 'id' => 'w05', 'v' => ['asset' => '0196f000-0000-7000-8000-000000000001', 'zoom' => 1, 'px' => 0.5, 'py' => 0.5]]]]];
        yield 'comment delete' => [['content' => [['c' => 'comments', 'id' => 'abcdefgh', 'v' => null]]]];
        yield 'finish that is not a boolean' => [['finish' => 'yes']];
    }

    public function testPayloadCapCsrfAndAccess(): void
    {
        $browser = self::createClient();

        // Not a member of the project at all.
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_2_EMAIL);
        $browser->request('GET', self::WORKSPACE_URL);
        self::assertResponseStatusCodeSame(403);
        $browser->request('POST', self::SAVE_URL, ['_token' => 'x', 'payload' => '{}']);
        self::assertResponseStatusCodeSame(403);

        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        $browser->request('POST', self::SAVE_URL, ['_token' => 'wrong', 'payload' => '{}']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'Platnost formuláře vypršela. Obnovte stránku.'], self::json($browser));

        self::postRaw($browser, $token, '{not json');
        self::assertResponseStatusCodeSame(422);

        self::postRaw($browser, $token, '[]');
        self::assertResponseStatusCodeSame(422);

        self::postRaw($browser, $token, json_encode(['design' => ['name' => str_repeat('a', CalendarSavePayload::MAX_BYTES)]], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Kalendář je příliš velký na uložení.'], self::json($browser));

        // An empty save is fine: it only bumps the revision.
        $answer = self::save($browser, self::SAVE_URL, ['content' => []], $token);
        self::assertResponseIsSuccessful();
        self::assertSame(1, self::dig($answer, 'calendar', 'revision'));

        $browser->request('GET', '/calendar/00000000-0000-0000-0000-00000000dead');
        self::assertResponseStatusCodeSame(404);
    }

    public function testHistoryEndpointListsNewestFirst(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        self::save($browser, self::SAVE_URL, ['content' => [['c' => 'clubs', 'id' => 'clubHasici', 'v' => ['code' => 'SDH', 'name' => 'Hasiči', 'pos' => 0]]]], $token);

        $browser->request('GET', self::WORKSPACE_URL . '/history');
        self::assertResponseIsSuccessful();
        $entries = self::json($browser)['entries'];
        self::assertIsArray($entries);
        self::assertCount(2, $entries);
        self::assertSame(['who' => 'Grafik', 'what' => 'Přidal spolek „Hasiči“'], array_diff_key((array) $entries[0], ['at' => true]));
        self::assertSame('Vytvořil kalendář', self::dig($entries, 1, 'what'));
        $at = self::dig($entries, 0, 'at');
        self::assertIsString($at);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $at));
    }

    private static function postRaw(KernelBrowser $browser, string $token, string $payload): void
    {
        $browser->request('POST', self::SAVE_URL, ['_token' => $token, 'payload' => $payload]);
    }
}
