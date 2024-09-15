<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Entity\Manual;

final class ManualPreviewController extends AbstractController
{
    #[Route(path: '/manual/{id}/preview', name: 'legacy_manual_preview')]
    public function legacyAction(Manual $manual): Response
    {
        return $this->redirectToRoute('manual_preview', [
            'projectSlug' => $manual->project->slug,
            'manualSlug' => $manual->slug,
        ]);
    }

    #[Route(path: '/manual/{projectSlug}/{manualSlug}', name: 'manual_preview')]
    public function __invoke(
        #[MapEntity(expr: 'repository.getBySlug(manualSlug, projectSlug)')]
        Manual $manual,
    ): Response {
        return $this->render('manual_preview.html.twig', [
            'project' => $manual->project,
            'manual' => $manual,
        ]);
    }
}
