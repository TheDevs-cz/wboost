<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\AiScan;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
     * The request's fields, whichever way they were sent: a JSON body, or
     * form fields (multipart for uploads) where a structured field (`fix`,
     * `fileIds`, …) may be a JSON string.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        if (str_starts_with((string) $request->headers->get('Content-Type'), 'application/json')) {
            $decoded = json_decode($request->getContent(), true);

            /** @var array<string, mixed> $body */
            $body = is_array($decoded) ? $decoded : [];

            return $body;
        }

        $fields = [];

        foreach ($request->request->all() as $key => $value) {
            if (is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[')) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : $value;
            }

            $fields[(string) $key] = $value;
        }

        return $fields;
    }

    /**
     * The stateless CSRF check every scan POST shares (id `ai_scan`): the
     * token travels as the form field `_token`, in the JSON body, or in the
     * `X-CSRF-Token` header.
     */
    private function assertCsrf(Request $request): void
    {
        $payload = $this->payload($request);
        $token = is_string($payload['_token'] ?? null) ? $payload['_token'] : (string) $request->headers->get('X-CSRF-Token');

        if (!$this->isCsrfTokenValid(StartAiScanController::CSRF_TOKEN_ID, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
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
