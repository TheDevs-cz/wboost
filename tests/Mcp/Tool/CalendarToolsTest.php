<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Tool;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Repository\CalendarHistoryRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Mcp\TestingMcpClient;
use WBoost\Web\Value\Calendar\CalendarAuthorKind;

/**
 * `list_calendars`, `get_calendar`, `update_calendar_content` — driven end to
 * end over `/_mcp` (the SDK derives a tool's input schema at compile time, so
 * a tool can be right in isolation and still fail to register).
 *
 * What is worth breaking the build over:
 *
 * - **One write path.** An MCP update is the web save: the same item rules
 *   refuse it, the same history sentence is recorded, the revision moves by
 *   one, and a refused call stores nothing — also when only its LAST
 *   operation is wrong.
 * - **No design through MCP.** There is no spelling of an operation that
 *   reaches a design key.
 * - **Role ∩ scope.** `calendars:read` does not write; a member the project
 *   is only shared with holds `calendars:write` and is still a calendar
 *   CLIENT, held to the client whitelist exactly as on the web.
 * - **A refusal reveals nothing.** A calendar of a project the caller cannot
 *   see answers word for word like an id that matches no row.
 *
 * @covers \WBoost\Web\Mcp\Tool\ListCalendarsTool
 * @covers \WBoost\Web\Mcp\Tool\GetCalendarTool
 * @covers \WBoost\Web\Mcp\Tool\UpdateCalendarContentTool
 */
final class CalendarToolsTest extends WebTestCase
{
    private const string EVENT_ID = 'Ab3dEf6hIj9kLm2n';

    private null|KernelBrowser $browser = null;

    public function testListsTheCalendarsOfAProject(): void
    {
        $list = $this->ok('list_calendars', ['projectId' => TestDataFixture::PROJECT_1_ID]);

        self::assertSame(TestDataFixture::PROJECT_1_ID, $list['projectId']);

        $calendars = array_column(self::arr($list['calendars']), null, 'id');
        self::assertEqualsCanonicalizing([TestDataFixture::CALENDAR_1_ID, TestDataFixture::CALENDAR_2_ID], array_keys($calendars));

        $unfinished = self::arr($calendars[TestDataFixture::CALENDAR_1_ID]);
        self::assertSame('Stolní kalendář 2027', $unfinished['name']);
        self::assertSame(TestDataFixture::CALENDAR_YEAR, $unfinished['year']);
        self::assertSame('week', $unfinished['type']);
        self::assertFalse($unfinished['finished']);
        self::assertFalse($unfinished['hasLayout']);
        self::assertSame('designer', $unfinished['lastChangeBy']);
        self::assertNotNull($unfinished['lastChangeAt']);
        self::assertStringEndsWith('/calendar/' . TestDataFixture::CALENDAR_1_ID, self::str($unfinished['url']));

        $finished = self::arr($calendars[TestDataFixture::CALENDAR_2_ID]);
        self::assertTrue($finished['finished']);
        self::assertTrue($finished['hasLayout']);
        self::assertNull($finished['lastChangeBy'], 'A calendar without history reports no last change.');
    }

    /**
     * Without a project the listing spans every reachable project — and only
     * those: PROJECT_2's calendar is not USER_1's to see.
     */
    public function testListsAcrossEveryReachableProjectAndNoFurther(): void
    {
        $list = $this->ok('list_calendars', []);

        self::assertNull($list['projectId']);

        $ids = array_column(self::arr($list['calendars']), 'id');
        self::assertContains(TestDataFixture::CALENDAR_1_ID, $ids);
        self::assertContains(TestDataFixture::CALENDAR_2_ID, $ids);
        self::assertNotContains(TestDataFixture::CALENDAR_3_ID, $ids);
    }

    public function testAForeignProjectIsIndistinguishableFromAnUnknownOne(): void
    {
        $unknownId = Uuid::uuid7()->toString();

        $foreign = $this->refused('list_calendars', ['projectId' => TestDataFixture::PROJECT_2_ID]);
        $unknown = $this->refused('list_calendars', ['projectId' => $unknownId]);

        self::assertStringEndsWith('(project_not_found)', $foreign);
        self::assertSame(
            str_replace(TestDataFixture::PROJECT_2_ID, '<id>', $foreign),
            str_replace($unknownId, '<id>', $unknown),
        );
    }

    public function testReadsACalendarWithoutItsStylesOrItsClientLink(): void
    {
        $result = $this->call('get_calendar', ['calendarId' => TestDataFixture::CALENDAR_2_ID]);
        self::assertFalse($result['isError'] ?? false, $result['content'][0]['text']);

        $text = $result['content'][0]['text'];
        $calendar = self::decode($text);

        self::assertSame(TestDataFixture::CALENDAR_2_ID, $calendar['id']);
        self::assertSame(TestDataFixture::PROJECT_1_ID, $calendar['projectId']);
        self::assertSame('Obecní kalendář', $calendar['name']);
        self::assertSame('designer', $calendar['role']);
        self::assertContains('shiftOps', self::arr($calendar['writableCollections']));
        self::assertSame('photoLeft', $calendar['preset']);

        // The layout says where things are — never how they look.
        self::assertSame(['boxes', 'order'], array_keys(self::arr($calendar['layout'])));
        self::assertArrayHasKey('photo', self::arr(self::dig($calendar, 'layout', 'boxes')));

        // The seeded content: four built-in waste kinds, switched off.
        self::assertSame(1, self::dig($calendar, 'content', 'v'));
        self::assertEqualsCanonicalizing(['kom', 'bio', 'plast', 'papir'], array_keys(self::arr(self::dig($calendar, 'content', 'wasteKinds'))));
        self::assertFalse(self::dig($calendar, 'content', 'wasteKinds', 'kom', 'on'));

        // Never-saved settings and an empty asset map are OBJECTS, not lists.
        self::assertStringContainsString('"features": {}', $text);
        self::assertStringContainsString('"assets": {}', $text);

        // The client link is a secret that grants anonymous write access.
        self::assertStringNotContainsString(TestDataFixture::CALENDAR_2_TOKEN, $text);
        self::assertStringNotContainsString('"styles"', $text);
    }

    public function testACalendarOfAForeignProjectIsIndistinguishableFromAnUnknownOne(): void
    {
        $unknownId = Uuid::uuid7()->toString();

        foreach (['get_calendar' => [], 'update_calendar_content' => ['operations' => [self::eventOperation()]]] as $tool => $extra) {
            $foreign = $this->refused($tool, ['calendarId' => TestDataFixture::CALENDAR_3_ID] + $extra);
            $unknown = $this->refused($tool, ['calendarId' => $unknownId] + $extra);

            self::assertStringEndsWith('(calendar_not_found)', $foreign, $tool);
            self::assertSame(
                str_replace(TestDataFixture::CALENDAR_3_ID, '<id>', $foreign),
                str_replace($unknownId, '<id>', $unknown),
                $tool,
            );
        }

        self::assertArrayNotHasKey('events', $this->calendar(TestDataFixture::CALENDAR_3_ID)->content, 'The refused update stored nothing in the foreign calendar.');
        self::assertStringEndsWith('(invalid_calendar_id)', $this->refused('get_calendar', ['calendarId' => 'kalendar']));
    }

    /**
     * The whole life of an item: created, re-sent unchanged, replaced, deleted
     * — each step through the web's own save, so each real change is one
     * revision and one history sentence, and the unchanged one is neither.
     */
    public function testUpdateCreatesReplacesAndDeletesItemsAndRecordsHistory(): void
    {
        $revision = $this->calendar(TestDataFixture::CALENDAR_1_ID)->revision;

        $created = $this->update(TestDataFixture::CALENDAR_1_ID, [
            self::eventOperation(),
            ['collection' => 'pageCaptions', 'id' => 'w05', 'value' => ['text' => 'Masopust, foto J. Novák'], 'label' => '5. týden'],
        ]);

        self::assertSame('designer', $created['role']);
        self::assertSame($revision + 1, $created['revision']);
        self::assertSame(
            [
                ['collection' => 'events', 'id' => self::EVENT_ID, 'status' => 'created'],
                ['collection' => 'pageCaptions', 'id' => 'w05', 'status' => 'created'],
            ],
            $created['results'],
        );
        self::assertSame('Den dětí', $created['content']['events'][self::EVENT_ID]['name']);
        self::assertEqualsCanonicalizing(
            ['Přidal akci „Den dětí“ — 1. 6.', 'Upravil popisek — 5. týden'],
            array_column($created['recorded'], 'what'),
        );
        self::assertSame(['designer', 'designer'], array_column($created['recorded'], 'who'));

        // Stored for real, and attributed to the token's user.
        $stored = $this->calendar(TestDataFixture::CALENDAR_1_ID);
        // assertEquals: jsonb stores an object's keys in its own order.
        self::assertEquals(
            ['date' => '2027-06-01', 'name' => 'Den dětí', 'club' => null, 'icon' => null],
            self::dig($stored->content, 'events', self::EVENT_ID),
        );
        self::assertSame(['text' => 'Masopust, foto J. Novák'], self::dig($stored->content, 'pageCaptions', 'w05'));

        $newest = self::getContainer()->get(CalendarHistoryRepository::class)->latest($stored->id, 1)[0];
        self::assertSame(CalendarAuthorKind::Designer, $newest->authorKind);
        self::assertSame(TestDataFixture::USER_1_ID, $newest->user?->id->toString());

        // The same operation again changes nothing and records nothing.
        $again = $this->update(TestDataFixture::CALENDAR_1_ID, [self::eventOperation()]);
        self::assertSame('unchanged', $again['results'][0]['status']);
        self::assertSame([], $again['recorded']);

        $replaced = $this->update(TestDataFixture::CALENDAR_1_ID, [self::eventOperation(['name' => 'Dětský den'])]);
        self::assertSame('updated', $replaced['results'][0]['status']);
        self::assertSame(['Upravil akci „Dětský den“ — 1. 6.'], array_column($replaced['recorded'], 'what'));

        $deleted = $this->update(TestDataFixture::CALENDAR_1_ID, [['collection' => 'events', 'id' => self::EVENT_ID, 'value' => null]]);
        self::assertSame('deleted', $deleted['results'][0]['status']);
        self::assertSame([], $deleted['content']['events']);
        self::assertSame(['Odebral akci „Dětský den“'], array_column($deleted['recorded'], 'what'));
        self::assertArrayNotHasKey(self::EVENT_ID, self::arr(self::dig($this->calendar(TestDataFixture::CALENDAR_1_ID)->content, 'events')));

        // get_calendar shows the same history the web does.
        $read = $this->ok('get_calendar', ['calendarId' => TestDataFixture::CALENDAR_1_ID]);
        self::assertSame('Odebral akci „Dětský den“', self::dig($read, 'history', 0, 'what'));
        self::assertSame('designer', self::dig($read, 'history', 0, 'who'));
    }

    /**
     * The item rules are the web's (`CalendarContentRules`): a wrong value is
     * `invalid_content`, and the call is atomic — the valid operation in front
     * of the wrong one is not stored either.
     */
    public function testAnInvalidItemRefusesTheWholeCall(): void
    {
        $revision = $this->calendar(TestDataFixture::CALENDAR_1_ID)->revision;

        $outOfYear = $this->refused('update_calendar_content', [
            'calendarId' => TestDataFixture::CALENDAR_1_ID,
            'operations' => [self::eventOperation(), self::eventOperation(['date' => '2031-06-01'], 'Zz9yXw8vUt7sRq6p')],
        ]);
        self::assertStringEndsWith('(invalid_content)', $outOfYear);
        self::assertStringContainsString('mimo rok kalendáře', $outOfYear);

        $missingKey = $this->refused('update_calendar_content', [
            'calendarId' => TestDataFixture::CALENDAR_1_ID,
            'operations' => [['collection' => 'events', 'id' => self::EVENT_ID, 'value' => ['date' => '2027-06-01', 'name' => 'Den dětí']]],
        ]);
        self::assertStringEndsWith('(invalid_content)', $missingKey);

        $unknownCollection = $this->refused('update_calendar_content', [
            'calendarId' => TestDataFixture::CALENDAR_1_ID,
            'operations' => [['collection' => 'layout', 'id' => self::EVENT_ID, 'value' => ['v' => 1]]],
        ]);
        self::assertStringEndsWith('(invalid_content)', $unknownCollection);

        $commentDelete = $this->refused('update_calendar_content', [
            'calendarId' => TestDataFixture::CALENDAR_1_ID,
            'operations' => [['collection' => 'comments', 'id' => self::EVENT_ID, 'value' => null]],
        ]);
        self::assertStringEndsWith('(invalid_content)', $commentDelete);

        $stored = $this->calendar(TestDataFixture::CALENDAR_1_ID);
        self::assertArrayNotHasKey('events', $stored->content);
        self::assertSame($revision, $stored->revision);
    }

    /**
     * The envelope: an operation is {collection, id, value, label} and nothing
     * else, so a design change cannot even be phrased; a missing `value` is not
     * a delete.
     */
    public function testAMalformedOperationIsRefusedBeforeAnythingIsSaved(): void
    {
        $calendar = $this->calendar(TestDataFixture::CALENDAR_1_ID);
        $revision = $calendar->revision;
        $name = $calendar->name;

        $cases = [
            'no_operations' => [],
            'invalid_operation' => [['collection' => 'events', 'id' => self::EVENT_ID, 'value' => null, 'design' => ['name' => 'Přejmenováno']]],
        ];

        foreach ($cases as $reason => $operations) {
            self::assertStringEndsWith(
                '(' . $reason . ')',
                $this->refused('update_calendar_content', ['calendarId' => TestDataFixture::CALENDAR_1_ID, 'operations' => $operations]),
            );
        }

        foreach ([
            [['collection' => 'wasteKinds', 'id' => 'kom']],
            [['collection' => 'events', 'value' => null]],
            [['collection' => 'events', 'id' => self::EVENT_ID, 'value' => ['a', 'b']]],
        ] as $operations) {
            self::assertStringEndsWith(
                '(invalid_operation)',
                $this->refused('update_calendar_content', ['calendarId' => TestDataFixture::CALENDAR_1_ID, 'operations' => $operations]),
            );
        }

        $stored = $this->calendar(TestDataFixture::CALENDAR_1_ID);
        self::assertSame($revision, $stored->revision);
        self::assertSame($name, $stored->name);
        self::assertArrayHasKey('kom', self::arr(self::dig($stored->content, 'wasteKinds')), 'A forgotten "value" must not delete the item.');
    }

    /**
     * SHARED_USER can only VIEW PROJECT_1. Their token holds `calendars:write`,
     * and they are still a calendar client: the whitelist is the web's.
     */
    public function testAReadOnlyMemberWritesAsAClientWithinTheClientWhitelist(): void
    {
        $token = TestDataFixture::MCP_TOKEN_CALENDARS_SHARED_USER;

        $read = $this->ok('get_calendar', ['calendarId' => TestDataFixture::CALENDAR_1_ID], $token);
        self::assertSame('client', $read['role']);
        self::assertNotContains('shiftOps', self::arr($read['writableCollections']));
        self::assertContains('events', self::arr($read['writableCollections']));

        $saved = $this->update(TestDataFixture::CALENDAR_1_ID, [
            self::eventOperation(),
            ['collection' => 'comments', 'id' => 'Cm1tAb2cDe3fGh4i', 'value' => ['date' => '2027-06-01', 'text' => 'Prosíme větším písmem.', 'author' => 'designer'], 'label' => '1. 6.'],
        ], $token);

        self::assertSame('client', $saved['role']);
        self::assertSame(['client', 'client'], array_column($saved['recorded'], 'who'));
        // The server stamps the author — what the caller claimed is ignored.
        self::assertSame('client', $saved['content']['comments']['Cm1tAb2cDe3fGh4i']['author']);

        $refused = $this->refused('update_calendar_content', [
            'calendarId' => TestDataFixture::CALENDAR_1_ID,
            'operations' => [
                ['collection' => 'names', 'id' => 'Nm1tAb2cDe3fGh4i', 'value' => ['date' => '2027-06-02', 'name' => 'Jarmila']],
                ['collection' => 'moonEdits', 'id' => 'Mo1tAb2cDe3fGh4i', 'value' => ['op' => 'add', 'date' => '2027-06-02', 'phase' => 2]],
            ],
        ], $token);
        self::assertStringEndsWith('(designer_only)', $refused);

        $stored = $this->calendar(TestDataFixture::CALENDAR_1_ID);
        self::assertArrayNotHasKey('names', $stored->content, 'The whitelisted operation of a refused call is not stored either.');
        self::assertArrayNotHasKey('moonEdits', $stored->content);
    }

    /**
     * `calendars:read` sees EXACTLY the two read tools — the calendar pair
     * implies no template scope — and is refused the write tool at the gate.
     */
    public function testCalendarReadTokenSeesTheReadToolsAndCannotWrite(): void
    {
        $token = TestDataFixture::MCP_TOKEN_CALENDARS_READ;
        $browser = $this->browser();

        $sessionId = TestingMcpClient::connect($browser, $token);
        TestingMcpClient::request($browser, 'tools/list', sessionId: $sessionId, token: $token);
        $listing = self::decode((string) $browser->getResponse()->getContent());
        $names = array_column(self::arr(self::dig($listing, 'result', 'tools')), 'name');
        sort($names);
        self::assertSame(['get_calendar', 'list_calendars'], $names);

        self::assertNotSame([], $this->ok('list_calendars', [], $token)['calendars']);

        $response = $this->raw('update_calendar_content', ['calendarId' => TestDataFixture::CALENDAR_1_ID, 'operations' => [self::eventOperation()]], $token);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertStringContainsString('scope="calendars:write"', (string) $response->headers->get('WWW-Authenticate'));
        self::assertArrayNotHasKey('events', $this->calendar(TestDataFixture::CALENDAR_1_ID)->content);
    }

    /**
     * The other direction: a template token learns nothing about calendars.
     */
    public function testATemplateTokenCannotReachTheCalendarTools(): void
    {
        $response = $this->raw('list_calendars', [], TestDataFixture::MCP_TOKEN_READ_ONLY);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertStringContainsString('scope="calendars:read"', (string) $response->headers->get('WWW-Authenticate'));
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     * @return array{collection: string, id: string, value: array<string, mixed>, label: string}
     */
    private static function eventOperation(array $overrides = [], string $id = self::EVENT_ID): array
    {
        return [
            'collection' => 'events',
            'id' => $id,
            'value' => $overrides + ['date' => '2027-06-01', 'name' => 'Den dětí', 'club' => null, 'icon' => null],
            'label' => '1. 6.',
        ];
    }

    /**
     * @param list<mixed> $operations
     * @return array{role: string, revision: int, results: list<array{collection: string, id: string, status: string}>, content: array<string, array<string, array<string, mixed>>>, recorded: list<array{who: string, what: string, at: string}>}
     */
    private function update(string $calendarId, array $operations, string $token = TestDataFixture::MCP_TOKEN_ACTIVE): array
    {
        /** @var array{role: string, revision: int, results: list<array{collection: string, id: string, status: string}>, content: array<string, array<string, array<string, mixed>>>, recorded: list<array{who: string, what: string, at: string}>} $payload */
        $payload = $this->ok('update_calendar_content', ['calendarId' => $calendarId, 'operations' => $operations], $token);

        return $payload;
    }

    /**
     * A successful call, decoded.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function ok(string $tool, array $arguments, string $token = TestDataFixture::MCP_TOKEN_ACTIVE): array
    {
        $result = $this->call($tool, $arguments, $token);
        self::assertFalse($result['isError'] ?? false, $result['content'][0]['text']);

        return self::decode($result['content'][0]['text']);
    }

    /**
     * A refused call: the refusal's text.
     *
     * @param array<string, mixed> $arguments
     */
    private function refused(string $tool, array $arguments, string $token = TestDataFixture::MCP_TOKEN_ACTIVE): string
    {
        $result = $this->call($tool, $arguments, $token);
        self::assertTrue($result['isError'] ?? false, sprintf('%s was expected to be refused: %s', $tool, $result['content'][0]['text']));

        return $result['content'][0]['text'];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{isError?: bool, content: list<array{text: string}>}
     */
    private function call(string $tool, array $arguments, string $token = TestDataFixture::MCP_TOKEN_ACTIVE): array
    {
        $response = $this->raw($tool, $arguments, $token);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $payload = self::decode((string) $response->getContent());
        self::assertArrayNotHasKey('error', $payload, (string) $response->getContent());
        self::assertIsArray($payload['result']);

        /** @var array{isError?: bool, content: list<array{text: string}>} $result */
        $result = $payload['result'];

        return $result;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function raw(string $tool, array $arguments, string $token): Response
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, $token);
        // An empty argument map must travel as `{}` — `[]` is a JSON list.
        TestingMcpClient::request($browser, 'tools/call', ['name' => $tool, 'arguments' => $arguments === [] ? new \stdClass() : $arguments], $sessionId, $token);

        return $browser->getResponse();
    }

    /**
     * The calendar as stored now — the identity map is cleared first, so this
     * is the database's answer and not the entity the request left behind.
     */
    private function calendar(string $calendarId): Calendar
    {
        $this->browser();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $calendar = $entityManager->find(Calendar::class, Uuid::fromString($calendarId));
        self::assertInstanceOf(Calendar::class, $calendar);

        return $calendar;
    }

    private function browser(): KernelBrowser
    {
        return $this->browser ??= self::createClient();
    }

    /**
     * One value deep inside a decoded answer; null when the path ends early.
     */
    private static function dig(mixed $value, int|string ...$path): mixed
    {
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    private static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
