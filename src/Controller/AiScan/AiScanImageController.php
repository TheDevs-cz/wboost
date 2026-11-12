<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\AiScan;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Services\AiScan\AiScanStorage;
use WBoost\Web\Services\Security\AiScanVoter;

/**
 * `GET /ai-scan/{id}/image/{which}` — the only way a scan's picture leaves
 * the private bucket towards a browser: behind the USE permission, as a
 * BUFFERED response (never a flushing stream — FrankenPHP), cacheable by that
 * browser alone. There is no public or presigned URL.
 *
 * `which`: `original` · `working` · `preview` · `candidate` (the kept one, or
 * `?n=` for a numbered candidate of the current run) · `proposal` (the
 * proposal with the review's fixes; the kept candidate until the first fix).
 * Add `?v=<seq>` to get past the browser's five-minute cache when the
 * picture changed.
 */
final class AiScanImageController extends AbstractController
{
    public function __construct(
        readonly private AiScanStorage $storage,
    ) {
    }

    #[Route(
        path: '/ai-scan/{id}/image/{which}',
        name: 'ai_scan_image',
        requirements: ['id' => '[0-9a-fA-F-]{36}', 'which' => 'original|working|preview|candidate|proposal'],
        methods: ['GET'],
    )]
    #[IsGranted(AiScanVoter::USE, 'scan')]
    public function __invoke(
        #[MapEntity(id: 'id')]
        AiScan $scan,
        string $which,
        Request $request,
    ): Response {
        $extension = is_string($scan->sourceMeta['ext'] ?? null) ? $scan->sourceMeta['ext'] : '';
        $contentType = is_string($scan->sourceMeta['contentType'] ?? null) ? $scan->sourceMeta['contentType'] : 'application/octet-stream';

        [$file, $type] = match ($which) {
            'original' => [AiScanStorage::originalName($extension), $contentType],
            'working' => [AiScanStorage::WORKING, 'image/webp'],
            'preview' => [AiScanStorage::PREVIEW, 'image/webp'],
            default => [AiScanStorage::candidateName(max(1, $request->query->getInt('n', $scan->keptCandidate ?? 1))), 'image/webp'],
        };

        $directory = AiScanStorage::directory($scan->project->id->toString(), $scan->id->toString(), $scan->storageSecret);
        $bytes = $which === 'proposal' ? $this->storage->read($directory, AiScanStorage::PROPOSAL) : null;
        $bytes ??= $this->storage->read($directory, $file);

        if ($bytes === null) {
            throw $this->createNotFoundException();
        }

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => $type,
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
