<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Isolates Sentry scope per request in FrankenPHP worker mode.
 *
 * In worker mode, PHP processes are long-running and handle multiple requests.
 * Without isolation, Sentry breadcrumbs, tags, and user context would leak
 * between requests.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 1000)]
#[AsEventListener(event: KernelEvents::TERMINATE, priority: -1000)]
final readonly class SentryRequestIsolationSubscriber
{
    public function __construct(
        private HubInterface $hub,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Push a new scope for this request - isolates breadcrumbs, tags, user, etc.
        $this->hub->pushScope();
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        // Pop the request scope, discarding all request-specific context
        $this->hub->popScope();
    }
}
