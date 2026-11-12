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
use WBoost\Web\Message\AiScan\ApplyAiScanFix;
use WBoost\Web\Repository\AiScanRepository;
use WBoost\Web\Services\AiScan\Template\ScanProposalView;
use WBoost\Web\Services\ReleaseSessionLock;
use WBoost\Web\Services\Security\AiScanVoter;

/**
 * `POST /ai-scan/{id}/fix` — one fix of the review or one picture decision
 * (`{fix: {type, …}}`); `POST /ai-scan/{id}/preview` — render the proposal's
 * picture again after a render that did not come back.
 *
 * USE permission; `ready` scans only (`wrong_state` otherwise — after accept
 * the scan page is read-only). The fix is saved when it is valid
 * (`invalid_fix` otherwise) whether or not the render came back:
 * `previewStale: true` says the picture is older than the proposal.
 *
 * The answer carries the whole proposal document again, so the screen never
 * has to guess what a fix changed.
 */
final class AiScanFixController extends AbstractController
{
    use AiScanResponses;

    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private AiScanRepository $repository,
        readonly private ScanProposalView $proposalView,
        readonly private ReleaseSessionLock $releaseSessionLock,
    ) {
    }

    #[Route(
        path: '/ai-scan/{id}/{action}',
        name: 'ai_scan_fix',
        requirements: ['id' => '[0-9a-fA-F-]{36}', 'action' => 'fix|preview'],
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

        $fix = $this->payload($request)['fix'] ?? null;

        if ($action === 'fix' && !is_array($fix)) {
            return new JsonResponse(['ok' => false, 'code' => 'invalid_fix', 'field' => 'fix'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // One bounded render follows: nothing of it needs the session.
        $this->releaseSessionLock->release($request);

        /** @var null|array<string, mixed> $fix */
        $result = $this->attempt($this->bus, new ApplyAiScanFix($scan->id, $user->id, $action === 'fix' ? $fix : null));

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return new JsonResponse(['ok' => true] + (is_array($result) ? $result : []) + ['proposal' => $this->proposalView->view($this->repository->get($scan->id))]);
    }
}
