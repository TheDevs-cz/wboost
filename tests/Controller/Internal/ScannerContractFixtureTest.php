<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Internal;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\Font;
use WBoost\Web\Entity\Project;
use WBoost\Web\Message\AiScan\CancelAiScan;
use WBoost\Web\Services\AiScan\AiScanDefinition;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\FontFace;

/**
 * The contract fixture, replayed from the app's side.
 *
 * `scanner/test/contract/scanner-api.v<N>.json` holds every kind of request
 * the scanner's client sends to `/internal/scanner/*` and what the real app
 * answered when it was recorded (`scanner/eval/record-contract.mjs`). The
 * scanner's own suite replays it against its client; this test replays it
 * against the REAL controllers. So a change on either side that alters the
 * wire — a renamed field, another status, a call that stops being idempotent
 * — fails in the build of the side that made it, and app N cannot drift
 * silently from scanner N (nor, while it accepts protocol N − 1, from the
 * scanner before).
 *
 * Every recorded request is sent as recorded, in order, with only the
 * placeholders replaced (`<scan:N>`, `<lease:…>`, `<SCANNER_TOKEN>`, byte
 * bodies). The answer must have the recorded HTTP status; `ok`, `code`,
 * `status`, `applied`, `cancel`, `candidate` and `reason` (and a job's
 * `kind`, `lane`, `run`, `try`, `approximate`, `leaseSeconds`) must be equal;
 * every other property must be there with the same JSON type — ids, tokens,
 * dates, sizes and pictures differ by nature.
 *
 * What the scanner cannot do is done here where the recording did it: an
 * upload before each scan's first intake claim, an intake the recording
 * passed off the record, the user's cancel.
 */
final class ScannerContractFixtureTest extends WebTestCase
{
    use AiScanTesting;

    private const string DIRECTORY = __DIR__ . '/../../../scanner/test/contract';

    /** Answer properties that must be EQUAL to the recording, wherever they are. */
    private const array EQUAL = ['ok', 'code', 'status', 'applied', 'cancel', 'candidate', 'reason', 'kind', 'lane', 'run', 'try', 'approximate', 'leaseSeconds', 'protocolVersion', 'mode', 'providerState'];

    /** Maps whose KEYS are data (file names): the recorded ones must be there, more may follow. */
    private const array OPEN_MAPS = ['job.definition.files'];

    private KernelBrowser $client;

    /** @var array<string, string> placeholder → value */
    private array $values = [];

    /** @var array<int, array{id: string, intake: null|array{run: int, lease: string}, stored: bool}> scans of the recording, by their number */
    private array $scans = [];

    private bool $cancelled = false;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::entitle(TestDataFixture::PROJECT_1_ID, true, null);
        self::switchOn();
        $this->values = ['<SCANNER_TOKEN>' => self::TOKEN];
        $this->scans = [];
        $this->cancelled = false;
    }

    public function testTheRecordingOfTheCurrentProtocolExists(): void
    {
        $version = self::getContainer()->get(AiScanDefinition::class)->protocolVersion();

        self::assertFileExists(
            sprintf('%s/scanner-api.v%d.json', self::DIRECTORY, $version),
            'The app speaks protocol ' . $version . ': the scanner build must record its contract fixture (scanner/eval/record-contract.mjs).',
        );
    }

    /**
     * App N against scanner N and scanner N − 1: every recording of a
     * protocol version this app accepts.
     */
    public function testEveryRecordedExchangeIsAnsweredAsRecorded(): void
    {
        $definition = self::getContainer()->get(AiScanDefinition::class);
        $replayed = 0;

        foreach (glob(self::DIRECTORY . '/scanner-api.v*.json') ?: [] as $file) {
            $fixture = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($fixture);
            $version = $fixture['protocolVersion'] ?? null;
            self::assertIsInt($version, basename($file));

            if (!$definition->acceptsProtocol($version)) {
                continue;
            }

            $entries = $fixture['entries'] ?? null;
            self::assertIsArray($entries);
            self::assertGreaterThan(40, count($entries), basename($file));
            $this->worldOfTheRecording();

            foreach ($entries as $index => $entry) {
                self::assertIsArray($entry);
                $this->replay(sprintf('%s #%d "%s"', basename($file), $index, self::str($entry['step'] ?? null)), $entry);
                $replayed++;
            }
        }

        self::assertGreaterThan(0, $replayed, 'no recording of an accepted protocol version was found');
    }

    // ---- one exchange ------------------------------------------------------

    /**
     * @param array<mixed> $entry
     */
    private function replay(string $label, array $entry): void
    {
        $request = $entry['request'] ?? null;
        $response = $entry['response'] ?? null;
        self::assertIsArray($request, $label);
        self::assertIsArray($response, $label);

        $this->before(self::str($entry['step'] ?? null), $request, $response);

        $server = ['REMOTE_ADDR' => self::SCAN_IP];
        $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', (string) $name))] = $this->fill(self::str($value), $label);
        }

        $body = $request['body'] ?? null;
        $content = null;

        if (is_string($body) && preg_match('/^<(\d+) bytes>$/', $body, $match) === 1) {
            $server['CONTENT_TYPE'] = 'application/octet-stream';
            $content = random_bytes(max(1, (int) $match[1]));
        } elseif (is_array($body)) {
            $server['CONTENT_TYPE'] = 'application/json';
            $content = $this->fill(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $label);
        }

        $this->client->request(self::str($request['method'] ?? null), $this->fill(self::str($request['path'] ?? null), $label), server: $server, content: $content);

        $answer = $this->client->getResponse();
        $raw = (string) $answer->getContent();
        $recorded = $response['body'] ?? null;

        self::assertSame($response['status'] ?? null, $answer->getStatusCode(), $label . ' — ' . substr($raw, 0, 300));

        if ($recorded === null) {
            // "Not the scanner": 404 and nothing else.
            self::assertSame('', $raw, $label);

            return;
        }

        if (is_string($recorded)) {
            // A picture: bytes, with the recorded content type.
            self::assertMatchesRegularExpression('/^<\d+ bytes>$/', $recorded, $label);
            self::assertNotSame('', $raw, $label);
            $contentType = self::str($response['contentType'] ?? null);
            self::assertNotSame('', $contentType, $label);
            self::assertStringStartsWith($contentType, (string) $answer->headers->get('Content-Type'), $label);

            return;
        }

        self::assertIsArray($recorded, $label);
        $actual = json_decode($raw, true);
        self::assertIsArray($actual, $label . ' — not JSON: ' . substr($raw, 0, 200));
        $this->sameShape($recorded, $actual, '', $label);
        $this->remember($recorded, $actual);
    }

    /**
     * What the outside world did at this point of the recording.
     *
     * @param array<mixed> $request
     * @param array<mixed> $response
     */
    private function before(string $step, array $request, array $response): void
    {
        $job = is_array($response['body'] ?? null) && is_array($response['body']['job'] ?? null) ? $response['body']['job'] : null;
        $isClaim = str_ends_with(self::str($request['path'] ?? null), '/claim');

        if ($isClaim && $job !== null && preg_match('/^<scan:(\d+)>$/', self::str($job['id'] ?? null), $match) === 1) {
            $number = (int) $match[1];

            if (!array_key_exists($number, $this->scans)) {
                // The user uploads: the picture the recording used was 1080 × 1350.
                $picture = self::picture(1080, 1350);
                $this->scans[$number] = ['id' => self::start(picture: $picture)->toString(), 'intake' => null, 'stored' => false];
                @unlink($picture);
            }

            if (($job['lane'] ?? null) === 'build' && self::text($this->scans[$number]['id'], 'status') === 'checking') {
                // The recording passed this scan's intake off the record.
                $intake = $this->scans[$number]['intake'];
                self::assertNotNull($intake, $step);
                self::passIntakeArtifacts($this->client, $this->scans[$number]['id'], [
                    'id' => $this->scans[$number]['id'], 'run' => $intake['run'], 'lease' => $intake['lease'], 'job' => [],
                ]);
            }
        }

        $path = self::str($request['path'] ?? null);

        if (preg_match('#/<scan:(\d+)>/(artifact/working|finish)#', $path, $match) === 1 && array_key_exists((int) $match[1], $this->scans)) {
            $number = (int) $match[1];
            $intake = $this->scans[$number]['intake'];
            $body = is_array($request['body'] ?? null) ? $request['body'] : [];
            $applied = is_array($response['body'] ?? null) && ($response['body']['applied'] ?? null) === true;

            if ($match[2] === 'artifact/working') {
                $this->scans[$number]['stored'] = true;
            } elseif (($body['outcome'] ?? null) === 'passed' && $applied && !$this->scans[$number]['stored'] && $intake !== null) {
                // The recording stored this intake's working copy and preview off the record.
                foreach (['working', 'preview'] as $which) {
                    self::scanner($this->client, 'PUT', sprintf('%s/artifact/%s?run=%d', $this->scans[$number]['id'], $which, $intake['run']), 'WEBP-BYTES-' . $which, $intake['lease']);
                }
            }
        }

        if (!$this->cancelled && str_contains($step, 'after the user cancelled') && preg_match('/^scan (\d+)/', $step, $match) === 1) {
            self::bus()->dispatch(new CancelAiScan(Uuid::fromString($this->scans[(int) $match[1]]['id']), Uuid::fromString(TestDataFixture::USER_1_ID)));
            $this->cancelled = true;
        }
    }

    /**
     * A claim answer names the placeholders of everything after it: the
     * scan's id and the lease token.
     *
     * @param array<mixed> $recorded
     * @param array<mixed> $actual
     */
    private function remember(array $recorded, array $actual): void
    {
        $recordedJob = $recorded['job'] ?? null;
        $job = $actual['job'] ?? null;

        if (!is_array($recordedJob) || !is_array($job)) {
            return;
        }

        $this->values[self::str($recordedJob['id'] ?? null)] = self::str($job['id'] ?? null);
        $this->values[self::str($recordedJob['leaseToken'] ?? null)] = self::str($job['leaseToken'] ?? null);

        if (($job['lane'] ?? null) === 'intake' && preg_match('/^<scan:(\d+)>$/', self::str($recordedJob['id'] ?? null), $match) === 1) {
            self::assertSame($this->scans[(int) $match[1]]['id'], $job['id'] ?? null, 'the claim handed out the scan the recording expects');
            $this->scans[(int) $match[1]]['stored'] = false;
            $this->scans[(int) $match[1]]['intake'] = ['run' => is_int($job['run'] ?? null) ? $job['run'] : 0, 'lease' => self::str($job['leaseToken'] ?? null)];
        }
    }

    /**
     * Replaces the placeholders of a recorded string. Lease tokens contain
     * a scan placeholder, so the longest names go first.
     */
    private function fill(string $text, string $label): string
    {
        $values = $this->values;
        uksort($values, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $text = strtr($text, $values);
        $text = str_replace(['<a lease token the app does not know>', '<a wrong token>'], ['0000-not-a-lease-token-0000', 'not-the-scanner-token-000000000000'], $text);

        self::assertDoesNotMatchRegularExpression('/<(scan|lease):/', $text, $label . ' — a placeholder the replay has no value for');

        return $text;
    }

    // ---- comparing an answer with its recording ----------------------------

    private function sameShape(mixed $recorded, mixed $actual, string $path, string $label): void
    {
        $where = $label . ' at ' . ($path === '' ? '(root)' : $path);
        $key = substr($path, (int) strrpos('.' . $path, '.'));

        if (in_array($key, self::EQUAL, true) && !is_array($recorded)) {
            self::assertSame($recorded, $actual, $where);

            return;
        }

        if (!is_array($recorded)) {
            self::assertSame(self::jsonType($recorded), self::jsonType($actual), $where);

            return;
        }

        self::assertIsArray($actual, $where);

        if ($recorded === [] || $actual === []) {
            // An empty list or an empty object: the other side must be a list or an object too — already asserted.
            return;
        }

        if (array_is_list($recorded)) {
            self::assertTrue(array_is_list($actual), $where . ' — a list was recorded');

            // Lists differ in length by nature (a project's fonts, a design's issues): every item has the first one's shape.
            if (is_array($recorded[0])) {
                foreach ($actual as $index => $item) {
                    $this->sameShape($recorded[0], $item, $path . '[]', $label . ' item ' . $index);
                }
            }

            return;
        }

        if (in_array($path, self::OPEN_MAPS, true)) {
            self::assertSame([], array_values(array_diff(array_keys($recorded), array_keys($actual))), $where . ' — recorded entries are missing');

            return;
        }

        $recordedKeys = array_keys($recorded);
        $actualKeys = array_keys($actual);
        sort($recordedKeys);
        sort($actualKeys);
        self::assertSame($recordedKeys, $actualKeys, $where . ' — the properties of the answer changed');

        foreach ($recorded as $name => $value) {
            $this->sameShape($value, $actual[$name], ($path === '' ? '' : $path . '.') . $name, $label);
        }
    }

    private static function jsonType(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_string($value) => 'string',
            default => 'array',
        };
    }

    // ---- the world the recording was made in -------------------------------

    /**
     * The recording's project has the font the recorded designs use
     * ("Arial (Arial Italique)"); the fixture project gets it too.
     */
    private function worldOfTheRecording(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $project = $manager->find(Project::class, Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        self::assertInstanceOf(Project::class, $project);

        $arial = new Font(Uuid::uuid7(), $project, new \DateTimeImmutable(), 'Arial', new FontFace('Arial Italique', 400, 'normal', 'fixtures/fonts/rubik-regular.ttf'));
        $arial->addFontFace(new FontFace('Arial', 400, 'normal', 'fixtures/fonts/rubik-regular.ttf'));
        $manager->persist($arial);
        $manager->flush();
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
