<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Sentry\State\HubInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Flushes the Sentry client transport on kernel.terminate.
 *
 * In FrankenPHP worker mode, PHP processes persist between requests —
 * register_shutdown_function callbacks never execute. The Sentry SDK
 * relies on shutdown to flush its transport. Without an explicit flush,
 * captured events stay in the queue and are never sent.
 *
 * Priority -512 ensures this runs:
 *  - AFTER BufferFlusher (priority 10) — monolog buffer is flushed, Sentry handler has captured events
 *  - BEFORE SentryRequestIsolationSubscriber (priority -1000) — scope data is still available
 */
#[AsEventListener(event: KernelEvents::TERMINATE, priority: -512)]
final readonly class SentryFlushListener
{
    public function __construct(
        private HubInterface $hub,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $client = $this->hub->getClient();

        if ($client !== null) {
            $client->flush();
        }
    }
}
