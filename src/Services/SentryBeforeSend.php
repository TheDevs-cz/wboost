<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Sentry\Event;
use Sentry\EventHint;
use WBoost\Web\Services\AiScan\AiScanSentryScrubber;

/**
 * Sentry `before_send` (config/packages/sentry.php): every event passes the
 * AI-scan scrubber, then the pre-app timeout gets honest attribution.
 *
 * ## A timeout raised before the app ran is not about the request it names
 *
 * Under FrankenPHP worker mode a request whose input phase outlives PHP's
 * timer (a multipart body trickling in over a slow uplink) dies with
 * "Maximum execution time … exceeded" at the worker runner's handler closure
 * (`FrankenPhpWorkerRunner.php`, deepest frame) — before Symfony handled it.
 * The Sentry request data and the Monolog buffer of that worker thread are
 * then still the PREVIOUS request's: Sentry WEB-4X was booked under
 * `/internal/scanner/claim` and health checks while the victims were
 * group-editor saves. Such an event loses the stale request/user, is grouped
 * on its own fingerprint and says where to find the real request.
 * `max_input_time` (.docker/php/wboost.ini) and the edge's request buffering
 * make it rare; when it happens, it must not send anyone after the wrong route.
 */
final readonly class SentryBeforeSend
{
    private const string WORKER_RUNNER = 'symfony/runtime/Runner/FrankenPhpWorkerRunner.php';

    public function __construct(
        private AiScanSentryScrubber $aiScanScrubber,
    ) {
    }

    public function __invoke(Event $event, null|EventHint $hint = null): Event
    {
        $event = ($this->aiScanScrubber)($event, $hint);

        if (self::isTimeoutBeforeTheApp($event)) {
            $event->setRequest([]);
            $event->setUser(null);
            $event->setTransaction(null);
            $event->setTag('request_context', 'unknown_before_app');
            $event->setFingerprint(['frankenphp-timeout-before-app']);
            $event->setExtra([
                ...$event->getExtra(),
                'attribution' => 'The request timed out while PHP read its input (typically a slow multipart upload), '
                    . 'before the app ran. URL, user and logs of this event would be the previous request on the same '
                    . 'worker thread, so they were dropped. Find the real request in the edge access log '
                    . '(/srv/traefik/logs/access.log): a POST ending at this timestamp.',
            ]);
        }

        return $event;
    }

    private static function isTimeoutBeforeTheApp(Event $event): bool
    {
        foreach ($event->getExceptions() as $exception) {
            if (!str_contains($exception->getValue(), 'Maximum execution time')) {
                continue;
            }

            $frames = $exception->getStacktrace()?->getFrames() ?? [];
            $deepest = end($frames);

            if ($deepest !== false && str_ends_with($deepest->getAbsoluteFilePath() ?? $deepest->getFile(), self::WORKER_RUNNER)) {
                return true;
            }
        }

        return false;
    }
}
