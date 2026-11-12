<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\AiScan;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
use WBoost\Web\Services\AiScan\Template\ScanPictureUpload;
use WBoost\Web\Services\AiScan\Template\ScanProposalView;
use WBoost\Web\Services\ReleaseSessionLock;
use WBoost\Web\Services\Security\AiScanVoter;
use WBoost\Web\Value\AiScan\AiScanStatus;

/**
 * `POST /ai-scan/{id}/pictures` (multipart) — pictures uploaded for the
 * proposal's places (ux.md S4b):
 *
 * - `pictures[]` without `place` — the multi-drop: the files go to the empty
 *   places in order (`assigned` says how many found one);
 * - `pictures[]` (one file) with `place` — that place's picture ("Nahrát",
 *   "Přidat ukázku", "Změnit").
 *
 * Every file goes through the gallery's upload path into the folder
 * `AI sken / {název}`, with the gallery's limits (10 MB decimal, pictures
 * only). Pictures picked from the gallery need no upload: send the fix
 * `assign_pictures` / `set_picture` to `/ai-scan/{id}/fix`.
 *
 * USE permission; `ready` scans only.
 */
final class AiScanPicturesController extends AbstractController
{
    use AiScanResponses;

    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private AiScanRepository $repository,
        readonly private ScanPictureUpload $upload,
        readonly private ScanProposalView $proposalView,
        readonly private ReleaseSessionLock $releaseSessionLock,
    ) {
    }

    #[Route(path: '/ai-scan/{id}/pictures', name: 'ai_scan_pictures', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    #[IsGranted(AiScanVoter::USE, 'scan')]
    public function __invoke(
        #[MapEntity(id: 'id')]
        AiScan $scan,
        Request $request,
    ): Response {
        $user = $this->getUser();
        assert($user instanceof User);
        $this->assertCsrf($request);

        if ($scan->status !== AiScanStatus::Ready) {
            return new JsonResponse(['ok' => false, 'code' => 'wrong_state'], Response::HTTP_CONFLICT);
        }

        $files = $request->files->all('pictures');
        $files = array_values(array_filter($files, static fn (mixed $file): bool => $file instanceof UploadedFile));
        $place = $request->request->getString('place');
        $refusal = $this->upload->refusal($files, $place !== '');

        if ($refusal !== null) {
            return new JsonResponse(['ok' => false, 'code' => 'invalid_fix'] + $refusal, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->releaseSessionLock->release($request);

        /** @var list<UploadedFile> $files */
        $fileIds = $this->upload->store($scan, $files);
        $fix = $place !== ''
            ? ['type' => 'set_picture', 'id' => $place, 'fileId' => $fileIds[0]]
            : ['type' => 'assign_pictures', 'fileIds' => $fileIds];

        $result = $this->attempt($this->bus, new ApplyAiScanFix($scan->id, $user->id, $fix, $fileIds));

        if ($result instanceof JsonResponse) {
            // Nothing took the files: they do not stay behind in the gallery.
            $this->upload->discard($scan, $fileIds);

            return $result;
        }

        return new JsonResponse(['ok' => true] + (is_array($result) ? $result : []) + [
            'fileIds' => $fileIds,
            'proposal' => $this->proposalView->view($this->repository->get($scan->id)),
        ]);
    }
}
