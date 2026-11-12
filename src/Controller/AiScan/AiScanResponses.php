<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\AiScan;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use WBoost\Web\Value\AiScan\AiScanRefusal;
use WBoost\Web\Value\AiScan\AiScanRefused;

/**
 * Shared by the scan controllers: a refusal is an ANSWER —
 * `{ok: false, code, …params}` with a fitting status — never an exception
 * that reaches the error handler (and Sentry).
 */
trait AiScanResponses
{
    /**
     * @param array<string, int|string> $params
     */
    private function refusal(AiScanRefusal $refusal, array $params = []): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'code' => $refusal->value, ...$params], $refusal->httpStatus());
    }

    /**
     * Dispatches and returns the handler's result, or the refusal it threw.
     */
    private function attempt(MessageBusInterface $bus, object $message): mixed
    {
        try {
            return $bus->dispatch($message)->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failed) {
            $cause = $failed->getPrevious();

            if ($cause instanceof AiScanRefused) {
                return $this->refusal($cause->refusal, $cause->params);
            }

            throw $failed;
        }
    }
}
