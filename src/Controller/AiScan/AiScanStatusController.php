<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\AiScan;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Services\AiScan\AiScans;
use WBoost\Web\Services\Security\AiScanVoter;

/**
 * `GET /ai-scan/{id}` — what the scan surface polls (every 2 s). USE
 * permission. Scalar columns only; the read writes nothing unless it meets an
 * overdue row, which the reaper then moves at once.
 */
final class AiScanStatusController extends AbstractController
{
    public function __construct(
        readonly private AiScans $scans,
    ) {
    }

    #[Route(path: '/ai-scan/{id}', name: 'ai_scan_status', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    #[IsGranted(AiScanVoter::USE, 'scan')]
    public function __invoke(
        #[MapEntity(id: 'id')]
        AiScan $scan,
    ): Response {
        $response = new JsonResponse(['ok' => true, 'scan' => $this->scans->view($scan)]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
