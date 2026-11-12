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
 * Scanner API — `POST {id}/faces`: one render of the lines in each face; bytes untouched.
 * (docs/architecture/ai-scan.md § "Scanner API"; logic in {@see ScannerApi}).
 */
final readonly class ScannerFacesController
{
    public function __construct(
        private ScannerApi $api,
    ) {
    }

    #[Route(path: '/internal/scanner/{id}/faces', name: 'internal_scanner_faces', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(ScannerServiceAuthenticator::ROLE)]
    public function __invoke(Request $request, string $id): Response
    {
        return $this->api->enter($request)
            ?? $this->api->faces($id, ScannerApi::body($request), $request->headers->get(ScannerApi::LEASE_HEADER));
    }
}
