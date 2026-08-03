<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\User;
use WBoost\Web\Services\Storage\ScanStorage;

/**
 * "Refresh" button behind the storage report — the same scan `app:storage:scan`
 * runs, so an admin does not have to wait for the nightly cron to see the
 * effect of a cleanup.
 *
 * Runs synchronously: the scan is read-only against storage and takes seconds
 * for the bucket sizes involved. If it ever grows past that, this is the one
 * place to move behind the async transport.
 */
final class RescanStorageController extends AbstractController
{
    public function __construct(
        readonly private ScanStorage $scanStorage,
    ) {
    }

    #[Route(path: '/admin/storage/rescan', name: 'admin_storage_rescan', methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function __invoke(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('storage_rescan', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $result = $this->scanStorage->scan();

        $this->addFlash('success', sprintf(
            'Úložiště načteno: %d souborů, z toho %d nepoužívaných.',
            $result->fileCount,
            $result->orphanCount,
        ));

        if ($result->danglingReferences !== []) {
            $this->addFlash('warning', sprintf(
                'Pozor: %d záznamů v databázi odkazuje na soubory, které v úložišti nejsou.',
                count($result->danglingReferences),
            ));
        }

        // Route name off a whitelist, never the raw Referer — that would be an
        // open redirect on an admin-authenticated POST.
        $back = $request->request->getString('back') === 'files' ? 'admin_storage_files' : 'admin_usage';

        return $this->redirectToRoute($back);
    }
}
