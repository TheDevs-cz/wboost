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
    /** A template made from a scan: long edge. */
    public const int TEMPLATE_MAX_LONG_EDGE = 5000;
    /** What the scanner may PUT as one artifact (the working copy is ≤ 4096 px). */
    public const int ARTIFACT_MAX_BYTES = 25_000_000;

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
