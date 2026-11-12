<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\AiScan;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Entity\User;
use WBoost\Web\Services\AiScan\AiScans;
use WBoost\Web\Services\AiScan\Template\ScanProposalView;
use WBoost\Web\Services\Security\AiScanVoter;

/**
 * `GET /ai-scan/{id}/proposal` — the review's document (ux.md S4, S4b):
 * layers, places for pictures, decisions, information, the project's faces.
 * USE permission; works on an `accepted` scan too (read-only) while the
 * proposal exists. `proposal: null` when there is none.
 */
final class AiScanProposalController extends AbstractController
{
    public function __construct(
        readonly private AiScans $scans,
        readonly private ScanProposalView $proposalView,
    ) {
    }

    #[Route(path: '/ai-scan/{id}/proposal', name: 'ai_scan_proposal', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    #[IsGranted(AiScanVoter::USE, 'scan')]
    public function __invoke(
        #[MapEntity(id: 'id')]
        AiScan $scan,
    ): Response {
        $scan = $this->scans->current($scan);
        $response = new JsonResponse(['ok' => true, 'scan' => $this->scans->view($scan), 'proposal' => $this->proposalView->view($scan)]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
