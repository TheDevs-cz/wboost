<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Calendar;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\AiScan\AiScanKind;

/**
 * The calendar side of the 2026-11-13 hardening: the commands of a calendar
 * candidate are what a model read off a picture. Whatever they hold — a NUL,
 * markup, an absurd number, an enormous string — the answer is a rendered
 * candidate or a refused one; never a 500, and nothing that cannot be stored.
 */
final class CalendarScanHostileCommandsTest extends WebTestCase
{
    use AiScanTesting;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::entitle(TestDataFixture::PROJECT_1_ID);
        self::switchOn();
    }

    public function testHostileCommandsAreAnsweredNeverAnError(): void
    {
        $scanId = self::start(TestDataFixture::PROJECT_1_ID, kind: AiScanKind::Calendar, calendarId: TestDataFixture::CALENDAR_1_ID)->toString();
        self::passIntake($this->client, $scanId);
        $build = self::claim($this->client, 'build');

        $nul = "a\u{0}b";
        $markup = '</script><script>window.__x=1</script><!--';
        $preset = ['op' => 'preset', 'preset' => 'photoRight'];

        $cases = [
            'a preset name with a NUL' => [['op' => 'preset', 'preset' => $nul]],
            'a preset name with markup' => [['op' => 'preset', 'preset' => $markup]],
            'a feature key with a NUL' => [$preset, ['op' => 'configure', 'features' => [$nul => true]]],
            'a feature key with markup' => [$preset, ['op' => 'configure', 'features' => [$markup => true]]],
            'a style value with a NUL' => [$preset, ['op' => 'layout', 'styles' => ['head' => ['bg' => ['color' => $nul]]]]],
            'a style value with markup' => [$preset, ['op' => 'layout', 'styles' => ['cal' => ['numberSide' => $markup]]]],
            'a style key with a NUL' => [$preset, ['op' => 'layout', 'styles' => [$nul => ['bg' => ['color' => '#112233']]]]],
            'a font with markup' => [$preset, ['op' => 'layout', 'styles' => ['month' => ['font' => $markup, 'color' => '#ffffff']]]],
            'a box key with a NUL' => [$preset, ['op' => 'layout', 'boxes' => [$nul => ['x' => 1, 'y' => 1, 'w' => 10, 'h' => 10]]]],
            'a box of absurd size' => [$preset, ['op' => 'layout', 'boxes' => ['logo' => ['x' => 1e12, 'y' => -1e12, 'w' => 1e9, 'h' => 1e9]]]],
            'an order with a megabyte string' => [$preset, ['op' => 'layout', 'order' => [str_repeat('A', 1_000_000)]]],
            'two thousand boxes' => [$preset, ['op' => 'layout', 'boxes' => array_fill_keys(array_map(static fn (int $i): string => 'box' . $i, range(1, 2000)), ['x' => 1, 'y' => 1, 'w' => 5, 'h' => 5])]],
            'an unknown op with a NUL' => [['op' => $nul]],
        ];

        $seq = 0;

        foreach ($cases as $name => $commands) {
            $answer = self::scanner($this->client, 'POST', $scanId . '/render', ['run' => $build['run'], 'seq' => ++$seq, 'commands' => $commands], $build['lease']);

            self::assertSame(200, $answer['status'], $name . ': ' . substr($answer['raw'], 0, 400));
            self::assertTrue($answer['json']['ok'] ?? false, $name . ': ' . substr($answer['raw'], 0, 400));
            self::assertArrayHasKey('candidate', $answer['json'], $name);
            $picture = self::dig($answer['json'], 'image', 'base64');
            self::assertLessThan(60_000, strlen($answer['raw']) - (is_string($picture) ? strlen($picture) : 0), $name . ': the answer does not echo what was sent');
        }

        // Whatever was stored on the way can be read back.
        self::assertLessThanOrEqual(count($cases), count(self::decoded(self::row($scanId)['candidates'])));
        self::assertSame('running', self::row($scanId)['status']);
    }
}
