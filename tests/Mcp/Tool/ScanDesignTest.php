<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Tool;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Message\Image\UploadFile;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Mcp\TestingMcpClient;
use WBoost\Web\Value\FileSource;

/**
 * `scan_design` — every action and every refusal (ux.md §10): the web's
 * rules, limits and counting, with channel `mcp`.
 *
 * @covers \WBoost\Web\Mcp\Tool\ScanDesignTool
 *
 * @phpstan-type Payload array{scanId: string, status: string, creditsLeft: null|int, queuePosition: null|int, pollAfterSeconds: null|int, retriesLeft: int, counted: bool, errorCode: null|string, errorSentence: null|string, canRetry: bool, canContinue: bool, unfinished: bool, toDecide: int, name: string, elements: mixed, placeholders: list<array{id: string, kind: string, picture: mixed}>, decisions: list<array{key: string, fixes: list<string>}>, fonts: list<string>, templateId: null|string, variantId: null|string, editorUrl: null|string, unfilledPictures: int, created: bool, scans: list<array{scanId: string, status: string, channel: string}>}
 */
final class ScanDesignTest extends WebTestCase
{
    use AiScanTesting;

    private const string PROJECT = TestDataFixture::PROJECT_1_ID;
    private const string MCP_TOKEN = TestDataFixture::MCP_TOKEN_ACTIVE;

    private null|KernelBrowser $browser = null;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        self::entitle(self::PROJECT);
        self::switchOn();
        self::consent(TestDataFixture::USER_1_EMAIL);
    }

    public function testStartStatusResultApply(): void
    {
        $started = $this->ok(['action' => 'start', 'projectId' => self::PROJECT, 'imageBase64' => $this->pictureBase64(), 'requestId' => 'agent-1', 'format' => ['unit' => 'px', 'width' => 1800, 'height' => 2400]]);
        $scanId = $started['scanId'];
        self::assertSame('checking', $started['status']);
        self::assertSame(9, $started['creditsLeft']);
        self::assertSame(5, $started['pollAfterSeconds']);
        self::assertSame('mcp', self::text($scanId, 'channel'));
        self::assertStringContainsString('"targetWidth": "1800"', self::text($scanId, 'options'));

        // The same requestId: the same scan, one reserve.
        self::assertSame($scanId, $this->ok(['action' => 'start', 'projectId' => self::PROJECT, 'imageBase64' => $this->pictureBase64(), 'requestId' => 'agent-1'])['scanId']);
        self::assertSame(['1:reserve:-1'], self::ledger($scanId));

        // No proposal yet.
        self::assertStringStartsWith('wrong_state:', $this->error(['action' => 'result', 'scanId' => $scanId]));
        self::assertStringStartsWith('wrong_state:', $this->error(['action' => 'apply', 'scanId' => $scanId]));

        $this->finishRich($scanId);

        $status = $this->ok(['action' => 'status', 'scanId' => $scanId]);
        self::assertSame(['ready', null, 2, false], [$status['status'], $status['pollAfterSeconds'], $status['retriesLeft'], $status['unfinished']]);
        self::assertNotSame('', self::text($scanId, 'seen_at'), 'the agent got the final state: no e-mail follows');

        $result = $this->call(['action' => 'result', 'scanId' => $scanId, 'image' => true]);
        self::assertFalse($result['isError'] ?? false);
        self::assertSame('image', $result['content'][1]['type'] ?? null, 'the render travels with the document');
        $proposal = $this->payload($result);
        self::assertSame(3, $proposal['toDecide']);
        self::assertSame(['bg', 'logo', 'foto'], array_column($proposal['placeholders'], 'id'));
        self::assertSame(['choose_font', 'keep_font'], $proposal['decisions'][0]['fixes']);

        // A refused fix names its entry; nothing is created.
        $templates = self::rowCount('template');
        $refused = $this->error(['action' => 'apply', 'scanId' => $scanId, 'fixes' => [['type' => 'keep_font', 'group' => 'headline'], ['type' => 'set_text', 'id' => 'nope', 'text' => 'x']]]);
        self::assertStringStartsWith('invalid_fix:', $refused);
        self::assertStringContainsString('fixes[1]', $refused);
        self::assertSame($templates, self::rowCount('template'));

        $pictureId = $this->galleryPicture();
        $applied = $this->ok([
            'action' => 'apply',
            'scanId' => $scanId,
            'name' => 'Výsledek U19',
            'fixes' => [
                ['type' => 'choose_font', 'group' => 'headline', 'face' => 'Rubik (Rubik Bold)'],
                ['type' => 'confirm_text', 'id' => 'sub', 'text' => 'Drobný text'],
                ['type' => 'set_input', 'id' => 'headline', 'input' => false],
                ['type' => 'add_placeholder', 'omitted' => 0],
                ['type' => 'remove_placeholder', 'id' => 'misto-1'],
                ['type' => 'placeholder_to_shape', 'id' => 'logo', 'shape' => 'rectangle'],
            ],
            'placeholders' => [['id' => 'foto', 'assetId' => $pictureId, 'kind' => 'fixed', 'fit' => 'contain']],
        ]);
        self::assertTrue($applied['created']);
        self::assertSame('accepted', $applied['status']);
        self::assertSame(1, $applied['unfilledPictures'], 'the grey background');
        self::assertStringContainsString('/template-variant/' . $applied['variantId'] . '/editor', (string) $applied['editorUrl']);
        self::assertSame($templates + 1, self::rowCount('template'));
        self::assertSame('Výsledek U19', self::connection()->fetchOne('SELECT name FROM template WHERE id = :id', ['id' => $applied['templateId']]));

        // The format chosen at start: the template is twice the picture.
        $variant = self::connection()->fetchAssociative('SELECT dimension_unit_width, dimension_unit_height, canvas FROM template_variant WHERE id = :id', ['id' => $applied['variantId']]);
        self::assertIsArray($variant);
        self::assertEquals([1800, 2400], [$variant['dimension_unit_width'], $variant['dimension_unit_height']]);
        self::assertStringContainsString('Drobný text', (string) json_encode(self::decoded($variant['canvas']), JSON_UNESCAPED_UNICODE));

        // Idempotent: the same ids, nothing new, the fixes are not applied again.
        $again = $this->ok(['action' => 'apply', 'scanId' => $scanId, 'fixes' => [['type' => 'set_text', 'id' => 'nope', 'text' => 'x']]]);
        self::assertFalse($again['created']);
        self::assertSame([$applied['templateId'], $applied['variantId']], [$again['templateId'], $again['variantId']]);
        self::assertSame($templates + 1, self::rowCount('template'));

        // Read-only after accept; the result can still be read.
        self::assertStringStartsWith('wrong_state:', $this->error(['action' => 'cancel', 'scanId' => $scanId]));
        self::assertSame('accepted', $this->ok(['action' => 'result', 'scanId' => $scanId])['status']);

        $list = $this->ok(['action' => 'list', 'projectId' => self::PROJECT]);
        self::assertSame([[$scanId, 'accepted', 'mcp']], array_map(static fn (array $row): array => [$row['scanId'], $row['status'], $row['channel']], $list['scans']));
        self::assertSame([], $this->ok(['action' => 'list', 'projectId' => self::PROJECT, 'status' => 'failed'])['scans']);
    }

    public function testStartFromAGalleryPicture(): void
    {
        $started = $this->ok(['action' => 'start', 'projectId' => self::PROJECT, 'imageId' => $this->galleryPicture(900, 1200)]);
        self::assertSame('checking', $started['status']);
        self::assertStringContainsString('"width": 900', self::text($started['scanId'], 'source_meta'));

        self::assertStringContainsString('exactly one of', $this->error(['action' => 'start', 'projectId' => self::PROJECT]));
        self::assertStringContainsString('No gallery picture', $this->error(['action' => 'start', 'projectId' => self::PROJECT, 'imageId' => Uuid::uuid7()->toString()]));
    }

    public function testCancelSaysWhetherItCounted(): void
    {
        $waiting = $this->ok(['action' => 'start', 'projectId' => self::PROJECT, 'imageBase64' => $this->pictureBase64()])['scanId'];
        $cancelled = $this->ok(['action' => 'cancel', 'scanId' => $waiting]);
        self::assertSame(['cancelled', false], [$cancelled['status'], $cancelled['counted']]);
        self::assertSame(['1:reserve:-1', '1:refund:1'], self::ledger($waiting));

        $running = $this->ok(['action' => 'start', 'projectId' => self::PROJECT, 'imageBase64' => $this->pictureBase64()])['scanId'];
        self::passIntake($this->browser(), $running);
        self::claim($this->browser(), 'build');
        $stopped = $this->ok(['action' => 'cancel', 'scanId' => $running]);
        self::assertSame(['cancelled', true], [$stopped['status'], $stopped['counted']], 'a running scan was already paid for');
        self::assertSame(['1:reserve:-1', '1:settle:0'], self::ledger($running));

        self::assertStringStartsWith('wrong_state:', $this->error(['action' => 'cancel', 'scanId' => $running]));
    }

    public function testRetryUntilRefusedAndContinueOnce(): void
    {
        $scanId = $this->ok(['action' => 'start', 'projectId' => self::PROJECT, 'imageBase64' => $this->pictureBase64()])['scanId'];
        self::assertStringStartsWith('wrong_state:', $this->error(['action' => 'retry', 'scanId' => $scanId]));

        foreach ([1, 0] as $left) {
            $this->fail_($scanId, 'provider_unavailable');
            $status = $this->ok(['action' => 'status', 'scanId' => $scanId]);
            self::assertSame(['failed', 'provider_unavailable', true], [$status['status'], $status['errorCode'], $status['canRetry']]);
            self::assertStringContainsString('Not counted', (string) $status['errorSentence']);

            $retried = $this->ok(['action' => 'retry', 'scanId' => $scanId]);
            self::assertSame(['queued', $left], [$retried['status'], $retried['retriesLeft']]);
        }

        $this->fail_($scanId, 'provider_unavailable');
        // Three failures of one class in a row tripped the circuit breaker; an admin switched it back on.
        self::assertStringStartsWith('temporarily_unavailable:', $this->error(['action' => 'retry', 'scanId' => $scanId]));
        self::switchOn();
        self::assertStringStartsWith('no_retries_left:', $this->error(['action' => 'retry', 'scanId' => $scanId]));
        self::assertFalse($this->ok(['action' => 'status', 'scanId' => $scanId])['canRetry']);

        // "continue": a photo of a print, once.
        $photo = $this->ok(['action' => 'start', 'projectId' => self::PROJECT, 'imageBase64' => $this->pictureBase64()])['scanId'];
        $intake = self::claim($this->browser(), 'intake');
        self::scanner($this->browser(), 'PUT', sprintf('%s/artifact/working?run=%d', $photo, $intake['run']), 'WEBP', $intake['lease']);
        self::scanner($this->browser(), 'POST', $photo . '/finish', ['run' => $intake['run'], 'outcome' => 'stopped', 'reason' => 'photo_of_print', 'usage' => self::usage(1)], $intake['lease']);

        $stopped = $this->ok(['action' => 'status', 'scanId' => $photo]);
        self::assertSame(['stopped', 'photo_of_print', true], [$stopped['status'], $stopped['errorCode'], $stopped['canContinue']]);
        self::assertSame('queued', $this->ok(['action' => 'continue', 'scanId' => $photo])['status']);
        self::assertStringStartsWith('wrong_state:', $this->error(['action' => 'continue', 'scanId' => $photo]));
    }

    public function testEveryStartRefusal(): void
    {
        $start = ['action' => 'start', 'projectId' => self::PROJECT, 'imageBase64' => $this->pictureBase64()];

        // Consent is given in the web, by the person.
        self::connection()->executeStatement('UPDATE "user" SET ai_scan_consent_version = NULL WHERE email = :e', ['e' => TestDataFixture::USER_1_EMAIL]);
        self::assertStringStartsWith('consent_required:', $this->error($start));
        self::consent(TestDataFixture::USER_1_EMAIL);

        self::assertStringStartsWith('unsupported_image:', $this->error(['imageBase64' => base64_encode('not a picture')] + $start));
        self::assertStringStartsWith('unsupported_image:', $this->error(['imageBase64' => '***'] + $start));
        self::assertStringStartsWith('unsupported_kind:', $this->error(['kind' => 'poster'] + $start));
        // A calendar scan is for ONE 7-day calendar of the project.
        self::assertStringStartsWith('wrong_state:', $this->error(['kind' => 'calendar'] + $start));
        self::assertStringStartsWith('wrong_state:', $this->error(['kind' => 'calendar', 'calendarId' => TestDataFixture::CALENDAR_3_ID] + $start));
        self::assertStringContainsString('format must be', $this->error(['format' => ['unit' => 'inch', 'width' => 1, 'height' => 1]] + $start));
        self::assertStringContainsString('No scan with id', $this->error(['action' => 'restore', 'scanId' => Uuid::uuid7()->toString()]));

        self::switchOn(\WBoost\Web\Value\AiScan\AiScanMode::Paused);
        self::assertStringStartsWith('temporarily_unavailable:', $this->error($start));
        self::switchOn();

        self::entitle(self::PROJECT, true, 0);
        self::assertStringStartsWith('limit_reached:', $this->error($start));

        self::entitle(self::PROJECT, false);
        self::assertStringStartsWith('not_enabled:', $this->error($start));

        // Nothing was stored or charged by any of them.
        self::assertSame(0, self::rowCount('ai_scan'));
        self::assertSame(0, self::rowCount('ai_credit_ledger'));
    }

    public function testScopeAndUsePermission(): void
    {
        $scanId = $this->ok(['action' => 'start', 'projectId' => self::PROJECT, 'imageBase64' => $this->pictureBase64()])['scanId'];

        // A token without `ai:scan` does not reach the tool at all.
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_DESIGN_ONLY);
        TestingMcpClient::request($browser, 'tools/call', ['name' => 'scan_design', 'arguments' => ['action' => 'status', 'scanId' => $scanId]], $sessionId, TestDataFixture::MCP_TOKEN_DESIGN_ONLY);
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('ai:scan', $content);
        self::assertStringNotContainsString('"checking"', $content);

        // Somebody else's scan does not exist for a user who may not use it.
        self::connection()->executeStatement("UPDATE mcp_access_token SET scopes = CAST(:scopes AS JSON) WHERE id = :id", ['scopes' => '["ai:scan"]', 'id' => TestDataFixture::MCP_TOKEN_SHARED_USER_ID]);

        foreach (['status', 'result', 'apply', 'cancel', 'retry', 'continue'] as $action) {
            self::assertStringContainsString('No scan with id', $this->error(['action' => $action, 'scanId' => $scanId], TestDataFixture::MCP_TOKEN_SHARED_USER), $action);
        }

        self::assertStringStartsWith('not_enabled:', $this->error(['action' => 'list', 'projectId' => self::PROJECT], TestDataFixture::MCP_TOKEN_SHARED_USER));
        self::assertStringStartsWith('not_enabled:', $this->error(['action' => 'start', 'projectId' => self::PROJECT, 'imageBase64' => $this->pictureBase64()], TestDataFixture::MCP_TOKEN_SHARED_USER));
        self::assertStringContainsString('No project with id', $this->error(['action' => 'list', 'projectId' => TestDataFixture::PROJECT_2_ID], TestDataFixture::MCP_TOKEN_SHARED_USER));
        self::assertSame('checking', self::text($scanId, 'status'));
    }

    public function testACalendarScanProposesALayoutApplySavesItAndRestorePutsThePreviousOneBack(): void
    {
        $calendarId = TestDataFixture::CALENDAR_2_ID;
        $column = static fn (string $name): string => (string) (is_scalar($value = self::connection()->fetchOne(sprintf('SELECT %s FROM calendar WHERE id = :id', $name), ['id' => $calendarId])) ? $value : '');
        $layoutBefore = self::decoded($column('layout'));
        $presetBefore = $column('layout_preset');

        $started = $this->ok(['action' => 'start', 'projectId' => self::PROJECT, 'kind' => 'calendar', 'calendarId' => $calendarId, 'imageBase64' => $this->pictureBase64()]);
        $scanId = $started['scanId'];
        self::assertSame('calendar', self::text($scanId, 'kind'));
        self::assertSame($calendarId, self::text($scanId, 'calendar_id'));

        // restore applies to an APPLIED calendar scan only.
        self::assertStringStartsWith('wrong_state:', $this->error(['action' => 'restore', 'scanId' => $scanId]));

        self::passIntake($this->browser(), $scanId);
        $build = self::claim($this->browser(), 'build');
        self::scanner($this->browser(), 'POST', $scanId . '/render', ['run' => $build['run'], 'seq' => 1, 'commands' => self::calendarCommands()], $build['lease']);
        $finish = self::scanner($this->browser(), 'POST', $scanId . '/finish', ['run' => $build['run'], 'outcome' => 'proposed', 'candidate' => 1, 'report' => self::calendarReport(1), 'usage' => self::usage(2)], $build['lease']);
        self::assertSame('ready', $finish['json']['status'] ?? null, $finish['raw']);

        /** @var array{kind: string, calendarId: string, hasLayout: bool, preset: string, layout: array{styles: array{cal: array{numberSide: string}}}, featuresOffered: list<string>, recognised: list<array{element: string, state: string, kept: bool}>, pictures: list<array{slot: string, current: bool}>, applied: mixed} $result */
        $result = $this->payload($this->call(['action' => 'result', 'scanId' => $scanId]));
        self::assertSame(['calendar', $calendarId, true, 'photoRight'], [$result['kind'], $result['calendarId'], $result['hasLayout'], $result['preset']]);
        self::assertSame('start', $result['layout']['styles']['cal']['numberSide']);
        self::assertSame(['qr', 'events'], $result['featuresOffered']);
        self::assertSame(['logo', 'header'], array_column($result['pictures'], 'slot'));
        self::assertNull($result['applied']);

        /** @var array{status: string, calendarId: string, features: list<string>, unfilledPictures: int, restoreUntil: null|string, created: bool, revision: int} $applied */
        $applied = $this->payload($this->call(['action' => 'apply', 'scanId' => $scanId, 'features' => ['qr', 'moon']]));
        self::assertSame(['accepted', $calendarId, ['qr'], 2, true], [$applied['status'], $applied['calendarId'], $applied['features'], $applied['unfilledPictures'], $applied['created']]);
        self::assertNotNull($applied['restoreUntil']);
        self::assertSame('scan', self::connection()->fetchOne('SELECT layout_origin FROM calendar WHERE id = :id', ['id' => $calendarId]));

        // Idempotent.
        /** @var array{created: bool, revision: int} $again */
        $again = $this->payload($this->call(['action' => 'apply', 'scanId' => $scanId]));
        self::assertFalse($again['created']);
        self::assertSame($applied['revision'], $again['revision']);

        // The layout is edited after the apply (another tool, the web): restore asks first.
        self::connection()->executeStatement("UPDATE calendar SET layout = jsonb_set(layout, '{boxes,logo,x}', '12') WHERE id = :id", ['id' => $calendarId]);
        $refused = $this->error(['action' => 'restore', 'scanId' => $scanId]);
        self::assertStringStartsWith('layout_changed:', $refused);
        self::assertStringContainsString('confirm: true', $refused);
        self::assertSame('scan', self::connection()->fetchOne('SELECT layout_origin FROM calendar WHERE id = :id', ['id' => $calendarId]));

        /** @var array{restored: bool, calendarId: string} $restored */
        $restored = $this->payload($this->call(['action' => 'restore', 'scanId' => $scanId, 'confirm' => true]));
        self::assertTrue($restored['restored']);
        self::assertEquals($layoutBefore, self::decoded($column('layout')));
        self::assertSame($presetBefore, $column('layout_preset'));
        self::assertSame('', $column('layout_origin'));

        /** @var array{restored: bool} $repeat */
        $repeat = $this->payload($this->call(['action' => 'restore', 'scanId' => $scanId]));
        self::assertFalse($repeat['restored']);

        /** @var array{scans: list<array{scanId: string, kind: string, calendarId: null|string}>} $list */
        $list = $this->payload($this->call(['action' => 'list', 'projectId' => self::PROJECT]));
        self::assertSame(['calendar', $calendarId], [$list['scans'][0]['kind'], $list['scans'][0]['calendarId']]);
    }

    // ---- helpers -----------------------------------------------------------

    private function finishRich(string $scanId): void
    {
        self::passIntake($this->browser(), $scanId);
        $build = self::claim($this->browser(), 'build');
        self::scanner($this->browser(), 'POST', $scanId . '/render', ['run' => $build['run'], 'seq' => 1, 'design' => self::richDesign()], $build['lease']);
        $finish = self::scanner($this->browser(), 'POST', $scanId . '/finish', [
            'run' => $build['run'], 'outcome' => 'proposed', 'candidate' => 1, 'report' => self::richReport(1), 'boxes' => self::richBoxes(), 'usage' => self::usage(2),
        ], $build['lease']);
        self::assertSame('ready', $finish['json']['status'] ?? null, $finish['raw']);
    }

    private function fail_(string $scanId, string $error): void
    {
        if (self::text($scanId, 'status') === 'checking') {
            self::passIntake($this->browser(), $scanId);
        }

        $build = self::claim($this->browser(), 'build');
        self::scanner($this->browser(), 'POST', $scanId . '/finish', ['run' => $build['run'], 'outcome' => 'failed', 'error' => $error, 'usage' => self::usage(1)], $build['lease']);
        self::assertSame('failed', self::text($scanId, 'status'));
    }

    private function pictureBase64(): string
    {
        $path = self::picture();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return base64_encode($bytes);
    }

    private function galleryPicture(int $width = 500, int $height = 500): string
    {
        $id = Uuid::uuid7();
        self::bus()->dispatch(new UploadFile($id, Uuid::fromString(self::PROJECT), FileSource::ProjectImage, new UploadedFile(self::picture($width, $height), 'obrazek.png', 'image/png', null, true)));

        return $id->toString();
    }

    /**
     * @param array<string, mixed> $arguments
     * @return Payload
     */
    private function ok(array $arguments, string $token = self::MCP_TOKEN): array
    {
        return $this->payload($this->call($arguments, $token));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function error(array $arguments, string $token = self::MCP_TOKEN): string
    {
        $result = $this->call($arguments, $token);
        self::assertTrue($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));

        return $result['content'][0]['text'] ?? '';
    }

    /**
     * @param array{isError?: bool, content: list<array{type?: string, text?: string}>} $result
     * @return Payload
     */
    private function payload(array $result): array
    {
        self::assertFalse($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));

        /** @var Payload $payload */
        $payload = json_decode($result['content'][0]['text'] ?? '', true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{isError?: bool, content: list<array{type?: string, text?: string}>}
     */
    private function call(array $arguments, string $token = self::MCP_TOKEN): array
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, $token);
        TestingMcpClient::request($browser, 'tools/call', ['name' => 'scan_design', 'arguments' => $arguments], $sessionId, $token);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{result?: array{isError?: bool, content: list<array{type?: string, text?: string}>}} $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('result', $payload, (string) $response->getContent());

        return $payload['result'];
    }

    private function browser(): KernelBrowser
    {
        return $this->browser ??= self::createClient();
    }
}
