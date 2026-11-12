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
use WBoost\Web\Message\AiScan\AcceptTemplateScan;
use WBoost\Web\Repository\AiScanRepository;
use WBoost\Web\Services\AiScan\AiScanUrls;
use WBoost\Web\Services\AiScan\AiScans;
use WBoost\Web\Services\AiScan\Template\ScanProposalView;
use WBoost\Web\Services\ReleaseSessionLock;
use WBoost\Web\Services\Security\AiScanVoter;
use WBoost\Web\Value\AiScan\AiScanKind;

/**
 * `POST /ai-scan/{id}/accept` — "Vytvořit šablonu": the proposal becomes a
 * template with one variant. USE permission (it works after the project was
 * switched off). Idempotent: a repeat answers with the same ids and
 * `created: false`.
 *
 * Refusals, nothing created: `wrong_state`; `stale_references` (a font or a
 * picture is gone — the answer carries the proposal again, whose decisions
 * now list `missing_font` / `missing_picture`); `renderer_busy` (try again
 * in a moment); `invalid_fix`.
 */
final class AiScanAcceptController extends AbstractController
{
    use AiScanResponses;

    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private AiScanRepository $repository,
        readonly private AiScans $scans,
        readonly private AiScanUrls $urls,
        readonly private ScanProposalView $proposalView,
        readonly private ReleaseSessionLock $releaseSessionLock,
    ) {
    }

    #[Route(path: '/ai-scan/{id}/accept', name: 'ai_scan_accept', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    #[IsGranted(AiScanVoter::USE, 'scan')]
    public function __invoke(
        #[MapEntity(id: 'id')]
        AiScan $scan,
        Request $request,
    ): Response {
        $user = $this->getUser();
        assert($user instanceof User);
        $this->assertCsrf($request);

        if ($scan->kind !== AiScanKind::Template) {
            return new JsonResponse(['ok' => false, 'code' => 'wrong_state'], Response::HTTP_CONFLICT);
        }

        $this->releaseSessionLock->release($request);
        $result = $this->attempt($this->bus, new AcceptTemplateScan($scan->id, $user->id));

        if ($result instanceof JsonResponse) {
            $refusal = json_decode((string) $result->getContent(), true);

            if (is_array($refusal) && ($refusal['code'] ?? null) === 'stale_references') {
                $result->setData($refusal + ['proposal' => $this->proposalView->view($this->repository->get($scan->id))]);
            }

            return $result;
        }

        assert(is_array($result));
        $variantId = is_string($result['variantId'] ?? null) ? $result['variantId'] : null;

        return new JsonResponse(['ok' => true] + $result + [
            'editorUrl' => $variantId === null ? null : $this->urls->editor($variantId),
            'scan' => $this->scans->view($this->repository->get($scan->id)),
        ]);
    }
}
