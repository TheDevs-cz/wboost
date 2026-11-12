<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\AiScan;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Entity\User;
use WBoost\Web\Repository\AiScanRepository;
use WBoost\Web\Services\AiScan\AiScans;

/**
 * `GET /ai-scans/mine` — the shell's poll (every 10 s while something is in
 * progress): the caller's OWN scans, nobody else's, whatever project they are
 * in. Needs no more than a login.
 */
final class MyAiScansController extends AbstractController
{
    public function __construct(
        readonly private AiScanRepository $repository,
        readonly private AiScans $scans,
    ) {
    }

    #[Route(path: '/ai-scans/mine', name: 'ai_scans_mine', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        $response = new JsonResponse([
            'ok' => true,
            'scans' => array_map(
                fn (AiScan $scan): array => [
                    ...$this->scans->view($scan),
                    'projectId' => $scan->project->id->toString(),
                ],
                $this->repository->mine($user->id),
            ),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
