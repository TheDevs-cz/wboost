<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Usage;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\Usage\RecordUserActivity;

/**
 * Records a lightweight "last seen" heartbeat for the authenticated web user on
 * every request, throttled to at most one write per minute per user. The
 * throttle gate is a free in-memory read of the already-hydrated
 * {@see User::$lastActivityAt} — no query — which is the whole reason that
 * timestamp lives on the user. Feeds {@see User::$lastActivityAt} and the
 * user_activity_day time-series behind the admin usage report.
 *
 * Tracking must NEVER break a request: every failure is swallowed and logged,
 * the same contract as {@see \WBoost\Web\Services\Usage\RecordExportUsage}.
 * Service-to-service API traffic (/api) and dev profiler/toolbar traffic (/_)
 * are excluded — this measures interactive human usage.
 */
#[AsEventListener(event: KernelEvents::REQUEST)]
final readonly class UserActivityListener
{
    private const int THROTTLE_SECONDS = 60;

    public function __construct(
        private Security $security,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();

        if (str_starts_with($path, '/api') || str_starts_with($path, '/_')) {
            return;
        }

        try {
            $user = $this->security->getUser();

            if (!$user instanceof User) {
                return;
            }

            $now = $this->clock->now();
            $lastActivityAt = $user->lastActivityAt;

            if (
                $lastActivityAt !== null
                && $now->getTimestamp() - $lastActivityAt->getTimestamp() < self::THROTTLE_SECONDS
            ) {
                return;
            }

            $this->bus->dispatch(new RecordUserActivity($user->id, $now));
        } catch (Throwable $e) {
            $this->logger->error('Failed to record user activity.', ['exception' => $e]);
        }
    }
}
