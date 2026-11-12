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
 * Scanner API — `POST tick`: every 30 s and at start — runs the reaper, takes the provider probe result.
 * (docs/architecture/ai-scan.md § "Scanner API"; logic in {@see ScannerApi}).
 */
final readonly class ScannerTickController
{
    public function __construct(
        private ScannerApi $api,
    ) {
    }

    #[Route(path: '/internal/scanner/tick', name: 'internal_scanner_tick', methods: ['POST'])]
    #[IsGranted(ScannerServiceAuthenticator::ROLE)]
    public function __invoke(Request $request): Response
    {
        $body = ScannerApi::body($request);

        return $this->api->enter($request, is_string($body['scannerVersion'] ?? null) ? $body['scannerVersion'] : null)
            ?? $this->api->tick($body);
    }
}
