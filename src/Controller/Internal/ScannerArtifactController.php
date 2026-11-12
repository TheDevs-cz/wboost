<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Internal;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Services\AiScan\ScannerApi;
use WBoost\Web\Services\Security\ScannerServiceAuthenticator;

/**
 * Scanner API — `PUT {id}/artifact/{working|preview}?run=N`: stores bytes the intake produced.
 * (docs/architecture/ai-scan.md § "Scanner API"; logic in {@see ScannerApi}).
 */
final readonly class ScannerArtifactController
{
    public function __construct(
        private ScannerApi $api,
    ) {
    }

    #[Route(path: '/internal/scanner/{id}/artifact/{which}', name: 'internal_scanner_artifact', methods: ['PUT'], requirements: ['id' => '[0-9a-fA-F-]{36}', 'which' => 'working|preview'])]
    #[IsGranted(ScannerServiceAuthenticator::ROLE)]
    public function __invoke(Request $request, string $id, string $which): Response
    {
        return $this->api->enter($request)
            ?? $this->api->artifact($id, $which, $request->query->get('run'), $request->headers->get(ScannerApi::LEASE_HEADER), $request->getContent());
    }
}
