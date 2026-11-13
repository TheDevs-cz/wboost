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
use WBoost\Web\Message\AiScan\CancelAiScan;
use WBoost\Web\Message\AiScan\ContinueAiScan;
use WBoost\Web\Message\AiScan\DiscardAiScan;
use WBoost\Web\Message\AiScan\RetryAiScan;
use WBoost\Web\Repository\AiScanRepository;
use WBoost\Web\Services\AiScan\AiScanAccess;
use WBoost\Web\Services\AiScan\AiScans;
use WBoost\Web\Services\Security\AiScanVoter;
use WBoost\Web\Value\AiScan\AiScanRefusal;

/**
 * `POST /ai-scan/{id}/{cancel|discard|retry|continue}`.
 *
 * - `cancel`, `discard` — USE permission: they work after the project was
 *   switched off, so nothing is ever stranded.
 * - `retry` ("Zkusit znovu"), `continue` ("Zkusit přesto") — a new START:
 *   USE of the scan AND the start permission on its project (entitlement here,
 *   mode and limits in the handler, each with its code).
 */
final class AiScanActionController extends AbstractController
{
    use AiScanResponses;

    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private AiScanAccess $access,
        readonly private AiScanRepository $repository,
        readonly private AiScans $scans,
    ) {
    }

    #[Route(
        path: '/ai-scan/{id}/{action}',
        name: 'ai_scan_action',
        requirements: ['id' => '[0-9a-fA-F-]{36}', 'action' => 'cancel|discard|retry|continue'],
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

        // The wizard and the lists post JSON, a plain form posts the field: both carry the token.
        $this->assertCsrf($request);

        if (in_array($action, ['retry', 'continue'], true) && !$this->access->isEntitled($user, $scan->project)) {
            return $this->refusal(AiScanRefusal::NotEnabled);
        }

        $result = $this->attempt($this->bus, match ($action) {
            'cancel' => new CancelAiScan($scan->id, $user->id),
            'discard' => new DiscardAiScan($scan->id, $user->id),
            'retry' => new RetryAiScan($scan->id, $user->id),
            default => new ContinueAiScan($scan->id, $user->id),
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return new JsonResponse(['ok' => true, 'scan' => $this->scans->view($this->repository->get($scan->id))]);
    }
}
