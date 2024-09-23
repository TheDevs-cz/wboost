<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Query\GetManualMockupPages;

final class ManualPreviewController extends AbstractController
{
    public function __construct(
        readonly private GetManualMockupPages $getManualMockupPages,
    ) {
    }

    #[Route(path: '/manual/{id}/preview', name: 'legacy_manual_preview')]
    public function legacyAction(Manual $manual): Response
    {
        return $this->redirectToRoute('manual_preview', [
            'projectSlug' => $manual->project->slug,
            'manualSlug' => $manual->slug,
        ]);
    }

    #[Route(path: '/nahled-manualu/{projectSlug}/{manualSlug}', name: 'manual_preview')]
    public function __invoke(
        #[MapEntity(expr: 'repository.getBySlug(manualSlug, projectSlug)')]
        Manual $manual,
    ): Response {
        return $this->render('manual_preview.html.twig', [
            'project' => $manual->project,
            'manual' => $manual,
            'mockup_pages' => $this->getManualMockupPages->allForManual($manual->id),
        ]);
    }
}
