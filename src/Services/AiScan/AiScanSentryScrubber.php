<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Sentry `before_send`: an event raised while serving a scan route never
 * carries the request body, the query string or cookies. Those requests hold
 * what must not leave the app — design documents, OCR text, picture bytes,
 * the scanner token and lease tokens (architecture.md §6 "Logs and Sentry").
 *
 * Expected refusals never get this far (they are answers, not exceptions);
 * this is the net under a real bug.
 */
final class AiScanSentryScrubber
{
    public function __invoke(Event $event, null|EventHint $hint = null): Event
    {
        $request = $event->getRequest();
        $url = $request['url'] ?? null;

        if (!is_string($url) || preg_match('#/(internal/scanner|ai-scan)(/|$|\?)|/ai-scans?/#', $url) !== 1) {
            return $event;
        }

        unset($request['data'], $request['cookies'], $request['query_string']);

        if (isset($request['headers']) && is_array($request['headers'])) {
            foreach (array_keys($request['headers']) as $name) {
                if (in_array(strtolower((string) $name), ['authorization', 'cookie', 'x-scan-lease'], true)) {
                    unset($request['headers'][$name]);
                }
            }
        }

        $event->setRequest($request);

        return $event;
    }
}
