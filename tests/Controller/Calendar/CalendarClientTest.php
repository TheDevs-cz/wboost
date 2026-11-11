<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Calendar;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * The client role — the holder of the link `/k/{token}` and a logged-in
 * member the project is only shared with for reading. The client fills
 * content; layout and style cannot be changed by any route, and that is
 * enforced by the server.
 *
 * @covers \WBoost\Web\Controller\Calendar\ClientCalendarController
 * @covers \WBoost\Web\Controller\Calendar\SaveClientCalendarController
 * @covers \WBoost\Web\Controller\Calendar\UploadClientCalendarAssetController
 * @covers \WBoost\Web\Controller\Calendar\ClientCalendarHistoryController
 * @covers \WBoost\Web\Controller\Calendar\CalendarClientLinkController
 */
final class CalendarClientTest extends WebTestCase
{
    use CalendarTesting;

    private const string CLIENT_URL = '/k/' . TestDataFixture::CALENDAR_2_TOKEN;
    private const string CLIENT_SAVE_URL = self::CLIENT_URL . '/save';
    private const string WORKSPACE_URL = '/calendar/' . TestDataFixture::CALENDAR_2_ID;

    public function testClientLinkOpensWithoutLoginAndAWrongTokenIsNotFound(): void
    {
        $browser = self::createClient();

        $bootstrap = self::bootstrap($browser, self::CLIENT_URL);

        self::assertSelectorExists('.cal-ws[data-calendar-workspace-mode-value="client"]');
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
        // Standalone: no app shell.
        self::assertSelectorNotExists('#sidebar');
        self::assertSame('noindex, nofollow', $browser->getResponse()->headers->get('X-Robots-Tag'));

        self::assertSame('client', $bootstrap['mode']);
        /** @var array<string, mixed> $env */
        $env = $bootstrap['env'];
        self::assertSame(['save' => self::CLIENT_SAVE_URL, 'asset' => self::CLIENT_URL . '/asset', 'history' => self::CLIENT_URL . '/history', 'export' => self::CLIENT_URL . '/export'], $env['urls']);
        self::assertArrayNotHasKey('siblings', $env);
        self::assertArrayNotHasKey('clientUrl', $env);
        // The link addresses the calendar by the token alone.
        self::assertStringNotContainsString('/calendar/' . TestDataFixture::CALENDAR_2_ID, (string) $browser->getResponse()->getContent());

        $browser->request('GET', '/k/' . str_repeat('ab', 32));
        self::assertResponseStatusCodeSame(404);

        $browser->request('POST', '/k/' . str_repeat('ab', 32) . '/save', ['_token' => 'x', 'payload' => '{}']);
        self::assertResponseStatusCodeSame(404);

        // Not token-shaped → no such route.
        $browser->request('GET', '/k/short');
        self::assertResponseStatusCodeSame(404);
    }

    public function testClientSavesWhitelistedContentAndItIsRecordedAsKlient(): void
    {
        $browser = self::createClient();
        $token = self::csrf(self::bootstrap($browser, self::CLIENT_URL));

        $answer = self::save($browser, self::CLIENT_SAVE_URL, [
            'content' => [
                ['c' => 'events', 'id' => 'clientEvent1', 'v' => self::event('Pouť'), 'label' => '1. 6.'],
                ['c' => 'pageCaptions', 'id' => 'w05', 'v' => ['text' => 'Zima na návsi'], 'label' => '5. týden'],
                ['c' => 'wasteExtras', 'id' => 'clientExtra1', 'v' => ['kind' => 'bio', 'date' => '2027-06-02'], 'label' => '2. 6.'],
            ],
        ], $token);

        self::assertResponseIsSuccessful();
        self::assertSame(['calendar', 'assets', 'savedAt'], array_keys($answer));

        $calendar = self::calendar(TestDataFixture::CALENDAR_2_ID);
        self::assertEquals(self::event('Pouť'), self::dig($calendar->content, 'events', 'clientEvent1'));
        self::assertSame(['text' => 'Zima na návsi'], self::dig($calendar->content, 'pageCaptions', 'w05'));
        self::assertSame(1, $calendar->revision);

        self::assertSame([
            ['Klient', 'Přidal svoz Bio odpad — 2. 6.'],
            ['Klient', 'Upravil popisek — 5. týden'],
            ['Klient', 'Přidal akci „Pouť“ — 1. 6.'],
        ], self::history(TestDataFixture::CALENDAR_2_ID));

        // The designer sees it, and the client reads the history through the link.
        $browser->request('GET', self::CLIENT_URL . '/history');
        self::assertResponseIsSuccessful();
        self::assertSame('Klient', self::dig(self::json($browser), 'entries', 0, 'who'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideForbiddenClientPayloads')]
    public function testClientCannotChangeDesignOrDesignerContent(array $payload): void
    {
        $browser = self::createClient();
        $token = self::csrf(self::bootstrap($browser, self::CLIENT_URL));

        // A whitelisted op in the same request must not slip through.
        $payload = self::withOp($payload, ['c' => 'events', 'id' => 'clientEvent1', 'v' => self::event('Pouť')]);

        $answer = self::save($browser, self::CLIENT_SAVE_URL, $payload, $token);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'Tuto změnu může udělat jen grafik.'], $answer);

        $calendar = self::calendar(TestDataFixture::CALENDAR_2_ID);
        self::assertSame(0, $calendar->revision);
        self::assertSame('Obecní kalendář', $calendar->name);
        self::assertSame(5, $calendar->step);
        self::assertTrue($calendar->finished);
        self::assertEquals(json_decode(TestDataFixture::CALENDAR_LAYOUT_JSON), $calendar->layout);
        self::assertArrayNotHasKey('events', $calendar->content);
        self::assertSame([], self::history(TestDataFixture::CALENDAR_2_ID));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideForbiddenClientPayloads(): iterable
    {
        yield 'layout' => [['design' => ['layout' => json_decode(TestDataFixture::CALENDAR_LAYOUT_JSON)]]];
        yield 'layout removed' => [['design' => ['layout' => null]]];
        yield 'name' => [['design' => ['name' => 'Přejmenováno']]];
        yield 'step only' => [['design' => ['step' => 3]]];
        yield 'empty design object' => [['design' => new \stdClass()]];
        yield 'features' => [['design' => ['features' => ['qr' => true]]]];
        yield 'finish' => [['finish' => true]];
        yield 'special pages' => [['content' => [['c' => 'specialPages', 'id' => 'intro', 'v' => null]]]];
        yield 'page QR' => [['content' => [['c' => 'pageQr', 'id' => 'w05', 'v' => null]]]];
        yield 'page images' => [['content' => [['c' => 'pageImages', 'id' => 'w05', 'v' => ['head' => null, 'bg' => null]]]]];
        yield 'shift operations' => [['content' => [['c' => 'shiftOps', 'id' => 'shiftOpId', 'v' => null]]]];
        yield 'moon edits' => [['content' => [['c' => 'moonEdits', 'id' => 'moonEditId', 'v' => null]]]];
        yield 'unknown collection' => [['content' => [['c' => 'layout', 'id' => 'abcdefgh', 'v' => null]]]];
    }

    public function testReadOnlyMemberGetsTheClientModeInsideTheApp(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::SHARED_USER_EMAIL);

        $bootstrap = self::bootstrap($browser, self::WORKSPACE_URL);

        self::assertSame('client', $bootstrap['mode']);
        self::assertSelectorExists('.cal-ws[data-calendar-workspace-mode-value="client"]');
        self::assertSelectorExists('#sidebar');
        /** @var array<string, mixed> $env */
        $env = $bootstrap['env'];
        // Their endpoints are the logged-in ones; the token is not handed out.
        $urls = $env['urls'];
        self::assertIsArray($urls);
        self::assertSame(['list', 'save', 'asset', 'history', 'export'], array_keys($urls));
        self::assertSame(self::WORKSPACE_URL . '/save', $urls['save']);
        self::assertArrayNotHasKey('clientUrl', $env);
        self::assertStringNotContainsString(TestDataFixture::CALENDAR_2_TOKEN, (string) $browser->getResponse()->getContent());

        $token = self::csrf($bootstrap);

        // Content yes — as Klient…
        self::save($browser, self::WORKSPACE_URL . '/save', ['content' => [['c' => 'names', 'id' => 'sharedName1', 'v' => ['date' => '2027-03-01', 'name' => 'Bedřich'], 'label' => '1. 3.']]], $token);
        self::assertResponseIsSuccessful();
        self::assertSame([['Klient', 'Přidal jméno „Bedřich“ — 1. 3.']], self::history(TestDataFixture::CALENDAR_2_ID));

        // …design no, and neither the designer-only endpoints.
        self::save($browser, self::WORKSPACE_URL . '/save', ['design' => ['name' => 'Nope']], $token);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Obecní kalendář', self::calendar(TestDataFixture::CALENDAR_2_ID)->name);

        foreach (['client-link', 'layout-import', 'document-text', 'delete'] as $endpoint) {
            $browser->request('POST', self::WORKSPACE_URL . '/' . $endpoint, ['_token' => $token, 'action' => 'regenerate']);
            self::assertResponseStatusCodeSame(403, $endpoint);
        }

        self::assertSame(TestDataFixture::CALENDAR_2_TOKEN, self::calendar(TestDataFixture::CALENDAR_2_ID)->clientToken);
    }

    public function testCommentsAreAppendOnlyAndStampedByTheServer(): void
    {
        $browser = self::createClient();
        $token = self::csrf(self::bootstrap($browser, self::CLIENT_URL));

        $comment = ['date' => '2027-06-01', 'text' => 'Prosíme jinou fotku', 'author' => 'designer', 'at' => '1999-01-01T00:00:00+00:00'];
        self::save($browser, self::CLIENT_SAVE_URL, ['content' => [['c' => 'comments', 'id' => 'comment0001', 'v' => $comment, 'label' => '1. 6.']]], $token);
        self::assertResponseIsSuccessful();

        $stored = self::dig(self::calendar(TestDataFixture::CALENDAR_2_ID)->content, 'comments', 'comment0001');
        self::assertIsArray($stored);
        self::assertSame('client', $stored['author']);
        self::assertNotSame('1999-01-01T00:00:00+00:00', $stored['at']);
        self::assertSame([['Klient', 'Komentář k 1. 6.: Prosíme jinou fotku']], self::history(TestDataFixture::CALENDAR_2_ID));

        // Re-sending it as stored is a no-op (a retried save), not an error.
        self::save($browser, self::CLIENT_SAVE_URL, ['content' => [['c' => 'comments', 'id' => 'comment0001', 'v' => ['date' => '2027-06-01', 'text' => 'Prosíme jinou fotku']]]], $token);
        self::assertResponseIsSuccessful();
        self::assertCount(1, self::history(TestDataFixture::CALENDAR_2_ID));

        // Changing or deleting it is refused.
        self::save($browser, self::CLIENT_SAVE_URL, ['content' => [['c' => 'comments', 'id' => 'comment0001', 'v' => ['date' => '2027-06-01', 'text' => 'Něco jiného']]]], $token);
        self::assertResponseStatusCodeSame(422);

        self::save($browser, self::CLIENT_SAVE_URL, ['content' => [['c' => 'comments', 'id' => 'comment0001', 'v' => ['date' => '2027-06-02', 'text' => 'Prosíme jinou fotku']]]], $token);
        self::assertResponseStatusCodeSame(422);

        self::save($browser, self::CLIENT_SAVE_URL, ['content' => [['c' => 'comments', 'id' => 'comment0001', 'v' => null]]], $token);
        self::assertResponseStatusCodeSame(422);

        self::assertSame('Prosíme jinou fotku', self::dig(self::calendar(TestDataFixture::CALENDAR_2_ID)->content, 'comments', 'comment0001', 'text'));
    }

    public function testClientUploadsPhotosButNotLogos(): void
    {
        $browser = self::createClient();
        $token = self::csrf(self::bootstrap($browser, self::CLIENT_URL));

        $photo = self::upload($browser, self::CLIENT_URL . '/asset', $token, 'photo', self::jpeg(60, 40), 'pout.jpg');
        self::assertResponseIsSuccessful();
        self::assertSame('photo', $photo['kind']);

        $logo = self::upload($browser, self::CLIENT_URL . '/asset', $token, 'logo', self::jpeg(60, 40), 'logo.jpg');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'Tuto změnu může udělat jen grafik.'], $logo);

        // The uploaded photo can be placed on a page by the client.
        self::save($browser, self::CLIENT_SAVE_URL, ['content' => [['c' => 'pagePhotos', 'id' => 'w05', 'v' => ['asset' => $photo['id'], 'zoom' => 1, 'px' => 0.5, 'py' => 0.5], 'label' => '5. týden']]], $token);
        self::assertResponseIsSuccessful();
        self::assertSame([['Klient', 'Nahrál fotku — 5. týden']], self::history(TestDataFixture::CALENDAR_2_ID));
    }

    public function testRegeneratingTheLinkKillsTheOldOne(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);
        $token = self::csrf(self::bootstrap($browser, self::WORKSPACE_URL));

        $browser->request('POST', self::WORKSPACE_URL . '/client-link', ['_token' => $token, 'action' => 'copied']);
        self::assertResponseIsSuccessful();
        self::assertSame(['url' => 'http://localhost' . self::CLIENT_URL], self::json($browser));

        $browser->request('POST', self::WORKSPACE_URL . '/client-link', ['_token' => $token, 'action' => 'regenerate']);
        self::assertResponseIsSuccessful();
        $url = self::json($browser)['url'];
        self::assertIsString($url);
        self::assertMatchesRegularExpression('~^http://localhost/k/[a-f0-9]{64}$~', $url);
        self::assertNotSame('http://localhost' . self::CLIENT_URL, $url);

        self::assertSame([
            ['Grafik', 'Vygeneroval nový odkaz pro klienta'],
            ['Grafik', 'Zkopíroval odkaz pro klienta'],
        ], self::history(TestDataFixture::CALENDAR_2_ID));

        $browser->request('POST', self::WORKSPACE_URL . '/client-link', ['_token' => $token, 'action' => 'other']);
        self::assertResponseStatusCodeSame(400);
        $browser->request('POST', self::WORKSPACE_URL . '/client-link', ['_token' => 'wrong', 'action' => 'regenerate']);
        self::assertResponseStatusCodeSame(403);

        $browser->request('GET', '/logout');
        $browser->request('GET', self::CLIENT_URL);
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', substr($url, strlen('http://localhost')));
        self::assertResponseIsSuccessful();
    }
}
