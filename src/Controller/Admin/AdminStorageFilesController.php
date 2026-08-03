<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\User;
use WBoost\Web\Query\GetStorageFiles;
use WBoost\Web\Value\StorageCategory;

/**
 * The individual-file drill-down behind the storage section of the admin
 * statistics page.
 */
final class AdminStorageFilesController extends AbstractController
{
    public function __construct(
        readonly private GetStorageFiles $getStorageFiles,
    ) {
    }

    #[Route(path: '/admin/storage/files', name: 'admin_storage_files')]
    #[IsGranted(User::ROLE_ADMIN)]
    public function __invoke(Request $request): Response
    {
        $projectId = $request->query->getString('project');
        $orphanedFilter = $request->query->getString('orphaned');
        $categoryFilter = $request->query->getString('category');
        $search = $request->query->getString('q');

        $orphaned = match ($orphanedFilter) {
            'yes' => true,
            'no' => false,
            default => null,
        };

        $category = $categoryFilter === '' ? null : StorageCategory::tryFrom($categoryFilter);

        return $this->render('admin/storage_files.html.twig', [
            'files' => $this->getStorageFiles->page(
                $projectId === '' ? null : $projectId,
                $orphaned,
                $category,
                $search === '' ? null : $search,
                $request->query->getInt('page', 1),
            ),
            'categories' => StorageCategory::cases(),
            'filters' => [
                'project' => $projectId,
                'orphaned' => $orphanedFilter,
                'category' => $categoryFilter,
                'q' => $search,
            ],
        ]);
    }
}
