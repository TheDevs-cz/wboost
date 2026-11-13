<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

/**
 * The app-side numbers of architecture.md §4.9. The scanner's own timings
 * travel in `resources/ai-scan/definition/definition.json`; the two lists are
 * kept in step by `AiScanDefinitionTest`.
 */
final class AiScanLimits
{
    /** Heartbeat H. */
    public const int HEARTBEAT_SECONDS = 15;
    /** Lease L, from the last heartbeat. */
    public const int LEASE_SECONDS = 120;
    /** The intake's lease, from its claim (it sends no heartbeat): its cap of 120 s + one app-call attempt. */
    public const int INTAKE_LEASE_SECONDS = 150;
    /** Run cap W — added to the estimate of a run on its automatic restart. */
    public const int RUN_CAP_SECONDS = 360;
    /** Scanner absent S. */
    public const int SCANNER_ABSENT_SECONDS = 60;
    /** Queue timeout Q. */
    public const int QUEUE_TIMEOUT_SECONDS = 3600;
    public const int PROJECT_IN_PROGRESS_MAX = 4;
    /** Scans waiting in total — `checking` and `queued` together. */
    public const int WAITING_TOTAL_MAX = 8;
    public const int RETENTION_DAYS = 30;
    /** Lost intake leases per try: the second stops the scan. */
    public const int MAX_INTAKE_TRIES = 2;
    /**
     * Graceful releases of a build per try (rule 7: the scanner hands the run
     * back — it is shutting down, or the provider is blocked). A release
     * consumes no automatic restart and starts the queue timeout again, so
     * without a bound a scan whose every run ends in a release would be
     * claimed — and paid for — for ever. Three are let through; the fourth
     * fails the scan as `interrupted`, refunded.
     *
     * Why three: a release needs a scanner restart (or a provider block) to
     * land INSIDE a run of this scan, and a run is at most W = 360 s. A
     * deploy of the scanner happens a few times a day at most, so one scan
     * meets one, on a bad day two; four in a row inside its ≤ 4 × 6 min of
     * work is not a deploy any more. And the bound is what holds the cost:
     * at most 4 released runs + the try's own two (one automatic restart) —
     * 6 × the $1.50 budget — where there was none.
     */
    public const int MAX_BUILD_RELEASES = 3;
    public const int MAX_RETRIES = 2;
    public const int SOURCE_FAILURES_MAX = 2;
    public const int SOURCE_FAILURE_WINDOW_DAYS = 30;
    public const int PROJECT_REFUSALS_PER_DAY = 3;
    public const int EVENTS_MAX = 200;

    /** Upload: 10 MB DECIMAL, like every other upload limit of the app. */
    public const int UPLOAD_MAX_BYTES = 10_000_000;
    public const int UPLOAD_MAX_SIDE = 8000;
    public const int UPLOAD_MAX_PIXELS = 36_000_000;
    public const int UPLOAD_MIN_SHORT_SIDE = 600;
    /**
     * A calendar page is 2.14 : 1 and is scanned for its LAYOUT, not its
     * texts: it is taken from **640 × 280 px** — the longer side at least
     * 640 px, the shorter at least 280 px. The rule used to be "short side
     * ≥ 300 px", which refused a page 640 px wide by one pixel (299 px high
     * at the calendar's own ratio). The width is what the reading needs: a
     * page 640 px wide reads as at 641 × 300 px, also when it is a little
     * flatter (640 × 280), while a page 600 px wide starts to lose lines
     * (scanner/eval/RESULTS.md §9.5, §9.6). Mirrors: `kinds.calendar.upload`
     * of the definition, `assets/ai-scan/file-checks.js`, `CAL.uploadSmall`,
     * the MCP tool's docblock and refusal text, the skill's `tools.md`,
     * `ux.md` — `AiScanValuesTest` reads them all.
     */
    public const int UPLOAD_MIN_SHORT_SIDE_CALENDAR = 280;
    public const int UPLOAD_MIN_LONG_SIDE_CALENDAR = 640;
    /** A template made from a scan: long edge. */
    public const int TEMPLATE_MAX_LONG_EDGE = 5000;
    /** What the scanner may PUT as one artifact (the working copy is ≤ 4096 px). */
    public const int ARTIFACT_MAX_BYTES = 25_000_000;

    /**
     * What ONE run (one usage row) may be recorded to have cost, as a
     * multiple of the engine budget of the definition ($1.50 → $4.50). The
     * figures come from another process: without a bound one lying or broken
     * report — tokens up to 2·10⁹, or the scanner's own cost for a model the
     * price table does not know — books thousands of dollars, closes the
     * day's ceilings and falsifies the admin's sums. A real run ends at its
     * budget (the engine checks it between turns, so a little above); three
     * times that is never honest. The engine's own figure is stored as sent.
     */
    public const int USAGE_COST_CAP_BUDGETS = 3;

    public const int DEFAULT_MONTHLY_LIMIT = 10;
    public const string TIMEZONE = 'Europe/Prague';
    /** Circuit breaker: this many scans in a row ending in the same failure class pause the feature. */
    public const int BREAKER_THRESHOLD = 3;

    /** Queue estimate defaults per kind until 10 finished runs exist: [p25, p75] seconds. */
    public const array DEFAULT_DURATION = [
        'template' => [60, 150],
        'calendar' => [60, 120],
    ];
}
