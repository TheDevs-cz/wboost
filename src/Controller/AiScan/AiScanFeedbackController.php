<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\AiScan;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\AiScan\RateAiScan;
use WBoost\Web\Services\AiScan\AiScanNotifications;
use WBoost\Web\Services\Security\AiScanVoter;

/**
 * `POST /ai-scan/{id}/rating` — "Jak se sken povedl?" (`rating`: `1` 👍 or
 * `-1` 👎, optional `note` ≤ 500 characters after a 👎).
 *
 * `POST /ai-scan/{id}/seen` — the requester's browser got the final state
 * on the scan surface, or they clicked a toast or a card: clears the badge
 * and cancels the e-mail. Somebody else's look changes nothing
 * (`seen: false`).
 *
 * USE permission.
 */
final class AiScanFeedbackController extends AbstractController
{
    use AiScanResponses;

    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private AiScanNotifications $notifications,
    ) {
    }

    #[Route(
        path: '/ai-scan/{id}/{action}',
        name: 'ai_scan_feedback',
        requirements: ['id' => '[0-9a-fA-F-]{36}', 'action' => 'rating|seen'],
        methods: ['POST'],
    )]
    #[IsGranted(AiScanVoter::USE, 'scan')]
    public function __invoke(
        #[MapEntity(id: 'id')]
        AiScan $scan,
        string $action,
        Request $request,
    ): Response {
        $user = $this->getUser();
        assert($user instanceof User);
        $this->assertCsrf($request);

        if ($action === 'seen') {
            return new JsonResponse(['ok' => true, 'seen' => $this->notifications->seen($scan->id, $user->id)]);
        }

        $payload = $this->payload($request);
        $rating = $payload['rating'] ?? null;
        $note = $payload['note'] ?? null;

        $result = $this->attempt($this->bus, new RateAiScan(
            $scan->id,
            $user->id,
            is_numeric($rating) ? (int) $rating : 0,
            is_string($note) ? $note : null,
        ));

        return $result instanceof JsonResponse ? $result : new JsonResponse(['ok' => true]);
    }
}
