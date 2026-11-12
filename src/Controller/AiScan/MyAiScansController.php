<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\AiScan;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Entity\User;
use WBoost\Web\Services\AiScan\AiScanLists;

/**
 * `GET /ai-scans/mine` — the shell's poll (every 10 s while something is in
 * progress): the caller's OWN scans, nobody else's, whatever project they are
 * in, and the badge counts (`inProgress` → the ring, `attention` → the count:
 * a proposal or a failure the requester has not seen). Needs no more than a
 * login.
 */
final class MyAiScansController extends AbstractController
{
    public function __construct(
        readonly private AiScanLists $lists,
    ) {
    }

    #[Route(path: '/ai-scans/mine', name: 'ai_scans_mine', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        $response = new JsonResponse(['ok' => true] + $this->lists->mine($user));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
