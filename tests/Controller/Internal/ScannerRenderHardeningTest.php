<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Internal;

use RuntimeException;
use Sensiolabs\GotenbergBundle\Exception\ClientException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Services\AiScan\AiScanTransitions;
use WBoost\Web\Services\Editor\RendererProbeInterface;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeRendererProbe;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;

/**
 * What a hostile picture — or a compromised scanner — can send to scanner API
 * `render` (red-team run 2026-11-13). Every case used to be an accepted
 * candidate, a 500, or a render that hung until the renderer's time limit:
 * the scan then failed `renderer_unavailable`, and three of those in a row
 * paused AI scan for everybody.
 *
 * Now: a document beyond the limits or holding a control character is a
 * rejected candidate (an answer); a candidate the WORKING renderer cannot
 * draw is one too, and a run that ends without a proposal because of its own
 * content counts against the picture, not against the circuit breaker.
 */
final class ScannerRenderHardeningTest extends WebTestCase
{
    use AiScanTesting;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // One container for the whole test: the fakes keep what a test sets.
        $this->client->disableReboot();
        self::entitle(TestDataFixture::PROJECT_1_ID, true, null);
        self::switchOn();
    }

    public function testADesignBeyondTheLimitsIsARejectedCandidateNotAnError(): void
    {
        $build = self::running($this->client);
        $id = $build['id'];

        $mutations = [
            'text-1mb' => static fn (array $d): array => self::withText($d, str_repeat('A', 1_000_000)),
            'text-100k' => static fn (array $d): array => self::withText($d, str_repeat('A', 100_000)),
            'elements-2000-texts' => static fn (array $d): array => self::withTexts($d, 2000),
            'elements-500-texts' => static fn (array $d): array => self::withTexts($d, 500),
            'xy-1e12' => static fn (array $d): array => self::withHeadline($d, ['x' => 1e12, 'y' => -1e12]),
            'size-1e9' => static fn (array $d): array => self::withHeadline($d, ['size' => 1e9]),
            'lineheight-1e6' => static fn (array $d): array => self::withHeadline($d, ['lineHeight' => 1e6]),
            'input-name-long' => static fn (array $d): array => self::withHeadline($d, ['input' => ['name' => str_repeat('N', 5000)]]),
            'id-constructor' => static fn (array $d): array => self::withHeadline($d, ['id' => 'constructor']),
            'id-reserved-misto' => static fn (array $d): array => self::withHeadline($d, ['id' => 'misto-1']),
        ];

        $seq = 0;

        foreach ($mutations as $name => $mutate) {
            $answer = self::scanner($this->client, 'POST', $id . '/render', ['run' => 1, 'seq' => ++$seq, 'design' => $mutate(self::design())], $build['lease']);

            self::assertSame(200, $answer['status'], $name);
            self::assertTrue($answer['json']['ok'] ?? false, $name);
            self::assertArrayHasKey('candidate', $answer['json'], $name);
            self::assertNull($answer['json']['candidate'], $name);
            self::assertArrayNotHasKey('image', $answer['json'], $name);

            $issues = self::decoded($answer['json']['issues'] ?? null);
            self::assertNotSame([], $issues, $name);

            foreach ($issues as $issue) {
                self::assertSame('limits', self::dig($issue, 'stage'), $name);
            }

            self::assertLessThan(8000, strlen($answer['raw']), $name . ': the answer never echoes the document');
        }

        $row = self::row($id);
        self::assertSame('[]', $row['candidates']);
        self::assertSame(0, $row['renders']);
        self::assertSame('running', $row['status']);
        self::assertSame([], $this->renderer()->calls, 'nothing beyond the limits reaches the renderer');

        // The run is as alive as before: a design within the limits renders.
        $fine = self::scanner($this->client, 'POST', $id . '/render', ['run' => 1, 'seq' => ++$seq, 'design' => self::design()], $build['lease']);
        self::assertSame(1, $fine['json']['candidate'] ?? null);
    }

    /**
     * The red-team case `text-nul-rtl-zw`: Postgres 22P05 at the write of the
     * candidate — a 500.
     */
    public function testAControlCharacterInADesignIsARejectedCandidate(): void
    {
        $build = self::running($this->client);
        $id = $build['id'];

        $hostile = "a\u{0}b\u{202E}cod.exe\u{200B}\u{200D}\u{FEFF}";

        foreach ([['text' => $hostile], ['input' => ['name' => "Nadpis\u{0}"]], ['text' => "bell\u{7}"]] as $seq => $change) {
            $answer = self::scanner($this->client, 'POST', $id . '/render', ['run' => 1, 'seq' => $seq + 1, 'design' => self::withHeadline(self::design(), $change)], $build['lease']);

            self::assertSame(200, $answer['status']);
            self::assertTrue($answer['json']['ok'] ?? false);
            self::assertRejected($answer);
            // By the parser's rule (a text) or, before it, by the limits (a name): either names the character.
            self::assertMatchesRegularExpression('/U\+000[07]/', json_encode($answer['json']['issues'] ?? null, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('\u0000', $answer['raw'], 'the answer does not carry the character back');
        }

        self::assertSame('[]', self::row($id)['candidates']);

        // The same text without the control character is a text like any
        // other: zero-width and direction marks are data, drawn and stored.
        $kept = "a b\u{202E}cod.exe\u{200B}\u{200D}\u{FEFF}";
        $answer = self::scanner($this->client, 'POST', $id . '/render', ['run' => 1, 'seq' => 9, 'design' => self::withHeadline(self::design(), ['text' => $kept])], $build['lease']);

        self::assertSame(1, $answer['json']['candidate'] ?? null, $answer['raw']);
        self::assertSame($kept, self::dig(self::decoded(self::row($id)['candidates']), 0, 'design', 'elements', 1, 'text'));
    }

    /**
     * The red-team cases `deep-json` and `text-lone-surrogate`: the body
     * could not be decoded, the app answered 409 `lease_lost` — "your run is
     * over" — for a run that was alive.
     */
    public function testAnUnreadableBodyIsAnInvalidRequestAndTheRunLivesOn(): void
    {
        $build = self::running($this->client);
        $id = $build['id'];

        $bodies = [
            'nested 5000 deep' => '{"run":1,"seq":1,"design":' . str_repeat('{"a":', 5000) . '1' . str_repeat('}', 5000) . '}',
            'an unpaired surrogate' => '{"run":1,"seq":1,"design":{"canvas":{"width":900,"height":1200},"elements":[{"kind":"text","id":"h","text":"bad \ud800 surrogate"}]}}',
            'not JSON' => '{"run":1,"seq":1,"design":',
            'three megabytes' => '{"run":1,"seq":1,"design":{"note":"' . str_repeat('x', 3_000_000) . '"}}',
        ];

        foreach ($bodies as $name => $body) {
            $answer = self::scanner($this->client, 'POST', $id . '/render', $body, $build['lease'], ['CONTENT_TYPE' => 'application/json']);

            self::assertSame(200, $answer['status'], $name);
            self::assertSame(['ok' => false, 'code' => 'invalid_request'], $answer['json'], $name);
        }

        self::assertSame('running', self::row($id)['status']);
        $fine = self::scanner($this->client, 'POST', $id . '/render', ['run' => 1, 'seq' => 2, 'design' => self::design()], $build['lease']);
        self::assertSame(1, $fine['json']['candidate'] ?? null);
    }

    /**
     * The renderer works, this candidate cannot be drawn in time: the answer
     * is about the candidate. The scanner's repeats of the call do not draw
     * it again; whatever the scanner then reports, the scan fails as `build`
     * — against the picture, with the circuit breaker untouched.
     */
    public function testACandidateTheWorkingRendererCannotDrawCountsAgainstTheScan(): void
    {
        $build = self::running($this->client);
        $id = $build['id'];

        $this->renderer()->throwOnRender = TemplateRenderUnavailable::timedOut(new RuntimeException('the render did not come back'));
        $this->probe()->responds = true;

        $answer = self::scanner($this->client, 'POST', $id . '/render', ['run' => 1, 'seq' => 1, 'design' => self::design()], $build['lease']);

        self::assertSame(200, $answer['status']);
        self::assertTrue($answer['json']['ok'] ?? false);
        self::assertRejected($answer);
        self::assertSame('render', self::dig($answer['json'], 'issues', 0, 'stage'));
        self::assertSame('render_failed', self::dig($answer['json'], 'issues', 0, 'code'));
        self::assertCount(1, self::decoded($answer['json']['issues'] ?? null));
        self::assertSame(1, $this->probe()->asked);

        // The scanner repeats a call that took too long, with the same seq.
        $this->renderer()->throwOnRender = new RuntimeException('the candidate must not be drawn a second time');
        $again = self::scanner($this->client, 'POST', $id . '/render', ['run' => 1, 'seq' => 1, 'design' => self::design()], $build['lease']);

        self::assertSame($answer['json'], $again['json']);
        self::assertSame(1, $this->probe()->asked, 'nor is the renderer asked again');
        self::assertSame('[]', self::row($id)['candidates']);

        // "The renderer was unavailable", says the scanner — the app knows better.
        $finish = self::scanner($this->client, 'POST', $id . '/finish', ['run' => 1, 'outcome' => 'failed', 'error' => 'renderer_unavailable', 'usage' => self::usage(2)], $build['lease']);

        self::assertSame(['ok' => true, 'applied' => true, 'status' => 'failed', 'code' => 'build'], $finish['json']);
        self::assertSame(1, self::number($id, 'failure_count'), 'it counts against the picture');
        self::assertSame(0, self::consecutiveFailures(), 'and not towards the circuit breaker');
        self::assertSame(['1:reserve:-1', '1:refund:1'], self::ledger($id));

        $events = array_values(array_filter(self::decoded(self::row($id)['events']), static fn (mixed $event): bool => is_array($event) && ($event['event'] ?? null) === AiScanTransitions::CANDIDATE_REFUSED));
        self::assertCount(1, $events);
        self::assertSame(['run' => 1, 'seq' => 1, 'code' => 'render'], array_intersect_key($events[0], ['run' => 0, 'seq' => 0, 'code' => 0]));
    }

    /**
     * The same when the renderer answers with an error of its own (a page
     * that crashed it) instead of running into the time limit: that used to
     * be an uncaught exception — a 500.
     */
    public function testARenderErrorOfAWorkingRendererIsAboutTheCandidateToo(): void
    {
        $build = self::running($this->client);
        $this->renderer()->throwOnRender = new ClientException('Gotenberg: 500 Internal Server Error');

        $answer = self::scanner($this->client, 'POST', $build['id'] . '/render', ['run' => 1, 'seq' => 1, 'design' => self::design()], $build['lease']);

        self::assertSame(200, $answer['status']);
        self::assertSame('render_failed', self::dig($answer['json'], 'issues', 0, 'code'));
    }

    /**
     * A renderer that cannot draw the trivial page either is down: the
     * answer the scanner retries, and a failure the breaker counts.
     */
    public function testARendererThatFailsItsProbeIsUnavailable(): void
    {
        $build = self::running($this->client);
        $id = $build['id'];

        $this->renderer()->throwOnRender = TemplateRenderUnavailable::timedOut(new RuntimeException('down'));
        $this->probe()->responds = false;

        foreach ([1, 2] as $attempt) {
            $answer = self::scanner($this->client, 'POST', $id . '/render', ['run' => 1, 'seq' => 1, 'design' => self::design()], $build['lease']);
            self::assertSame(['ok' => false, 'code' => 'renderer_unavailable'], $answer['json']);
            self::assertSame($attempt, $this->probe()->asked, 'every attempt is a fresh question');
        }

        $finish = self::scanner($this->client, 'POST', $id . '/finish', ['run' => 1, 'outcome' => 'failed', 'error' => 'renderer_unavailable', 'usage' => self::usage(2)], $build['lease']);

        self::assertSame('renderer_unavailable', $finish['json']['code'] ?? null);
        self::assertSame(0, self::number($id, 'failure_count'), 'not the picture\'s fault');
        self::assertSame(1, self::consecutiveFailures());
        self::assertSame('renderer_unavailable', self::runtimeValue('failure_class'));
    }

    public function testThreeScansFailingOverTheirOwnContentDoNotPauseAiScan(): void
    {
        foreach ([1, 2, 3, 4] as $n) {
            $build = self::running($this->client);
            $refused = self::scanner($this->client, 'POST', $build['id'] . '/render', ['run' => 1, 'seq' => 1, 'design' => self::withTexts(self::design(), 2000)], $build['lease']);
            self::assertRejected($refused);

            $finish = self::scanner($this->client, 'POST', $build['id'] . '/finish', ['run' => 1, 'outcome' => 'failed', 'error' => $n === 4 ? 'timeout' : 'build', 'usage' => self::usage(2)], $build['lease']);
            self::assertSame('failed', $finish['json']['status'] ?? null);
            self::assertSame(1, self::number($build['id'], 'failure_count'));
        }

        self::assertSame('on', self::runtimeValue('mode'));
        self::assertSame(0, self::consecutiveFailures());
        self::assertNull(self::runtimeValue('breaker_tripped_at'));

        // …while a build that failed for no reason the app can name still counts.
        $build = self::running($this->client);
        self::scanner($this->client, 'POST', $build['id'] . '/finish', ['run' => 1, 'outcome' => 'failed', 'error' => 'build', 'usage' => self::usage(2)], $build['lease']);

        self::assertSame(1, self::consecutiveFailures());
        self::assertSame('build', self::runtimeValue('failure_class'));
    }

    /**
     * @param array{status: int, json: array<string, mixed>, raw: string} $answer
     */
    private static function assertRejected(array $answer): void
    {
        self::assertSame(200, $answer['status'], $answer['raw']);
        self::assertTrue($answer['json']['ok'] ?? false, $answer['raw']);
        self::assertArrayHasKey('candidate', $answer['json'], $answer['raw']);
        self::assertNull($answer['json']['candidate'], $answer['raw']);
    }

    private static function consecutiveFailures(): int
    {
        $count = self::runtimeValue('consecutive_failures');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function renderer(): FakeTemplateVariantImageRenderer
    {
        // In the test env the interface aliases to the fake; PHPStan reads the
        // dev container, where it is the real implementation.
        $renderer = $this->client->getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }

    private function probe(): FakeRendererProbe
    {
        $probe = $this->client->getContainer()->get(RendererProbeInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeRendererProbe::class, $probe);

        return $probe;
    }

    /**
     * @param array<array-key, mixed> $design
     * @param array<string, mixed> $change
     * @return array<array-key, mixed>
     */
    private static function withHeadline(array $design, array $change): array
    {
        $elements = $design['elements'] ?? null;
        self::assertIsArray($elements);
        self::assertIsArray($elements[1] ?? null);
        $elements[1] = $change + $elements[1];
        $design['elements'] = $elements;

        return $design;
    }

    /**
     * @param array<array-key, mixed> $design
     * @return array<array-key, mixed>
     */
    private static function withText(array $design, string $text): array
    {
        return self::withHeadline($design, ['text' => $text]);
    }

    /**
     * @param array<array-key, mixed> $design
     * @return array<array-key, mixed>
     */
    private static function withTexts(array $design, int $count): array
    {
        $elements = $design['elements'] ?? null;
        self::assertIsArray($elements);

        for ($i = 0; $i < $count; $i++) {
            $elements[] = ['kind' => 'text', 'id' => 't' . $i, 'text' => 'T' . $i, 'font' => self::FACE, 'size' => 12, 'x' => 10, 'y' => 10 + ($i % 100) * 12, 'width' => 200];
        }

        $design['elements'] = $elements;

        return $design;
    }
}
