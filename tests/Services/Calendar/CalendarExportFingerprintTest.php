<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Calendar;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Calendar\CalendarModuleInliner;

/**
 * The renderer fingerprint is what a stored PDF export is compared with: a
 * calendar whose export was made by another fingerprint is exported again
 * (docs/architecture/calendars.md § "The job" — Reuse). It hashes the page
 * modules (`assets/calendar/{model,generate,render,editor,headless}/*.js`)
 * and the two headless templates.
 *
 * AI scan for calendars (P5) was built so that it changes NONE of them — the
 * marker of a scanned layout lives beside the design, `numberSide: middle`
 * was only opened on the server-side patch path, the scan's browser code is
 * under `ui/` and `state/`. This test pins the fingerprint the renderer had
 * before P5.
 *
 * It fails on every later change of the renderer too. That is the point:
 * changing the renderer invalidates every stored export and changes what a
 * print PDF looks like, so it must be a decision — when it is, put the new
 * value here in the same commit and say so in the commit message.
 *
 * @covers \WBoost\Web\Services\Calendar\CalendarModuleInliner
 */
final class CalendarExportFingerprintTest extends TestCase
{
    private const string FINGERPRINT = 'ebae7cfa04614423b2a07dfdee4e3418';

    public function testTheRendererIsWhatItWasBeforeCalendarScans(): void
    {
        $inliner = new CalendarModuleInliner(dirname(__DIR__, 3) . '/assets/calendar');

        self::assertSame(
            self::FINGERPRINT,
            $inliner->fingerprint(),
            'A file under assets/calendar/{model,generate,render,editor,headless}/ or a headless template changed: '
            . 'every stored calendar PDF export is invalidated and print output may differ. '
            . 'If that is intended, update FINGERPRINT in this test in the same commit.',
        );
    }

    public function testTheScanCodeIsOutsideTheFingerprint(): void
    {
        // Where calendar-scan code may live without touching the renderer.
        self::assertNotContains('ui', CalendarModuleInliner::DIRECTORIES);
        self::assertNotContains('state', CalendarModuleInliner::DIRECTORIES);

        foreach (CalendarModuleInliner::DIRECTORIES as $directory) {
            foreach (glob(dirname(__DIR__, 3) . '/assets/calendar/' . $directory . '/*.js') ?: [] as $path) {
                self::assertStringNotContainsString('layoutOrigin', (string) file_get_contents($path), basename($path) . ' knows the marker of a scanned layout — it belongs beside the design, not in it.');
            }
        }
    }
}
