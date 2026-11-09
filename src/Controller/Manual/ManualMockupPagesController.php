<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Query\GetManualPageList;
use WBoost\Web\Services\Security\ManualVoter;
use WBoost\Web\Value\MockupPageLayout;

/**
 * The "Stránky" tab: mockup pages + custom content pages in their shared
 * order (the route name predates P2 and is kept).
 */
final class ManualMockupPagesController extends AbstractController
{
    public function __construct(
        readonly private GetManualPageList $getManualPageList,
    ) {
    }

    #[Route(path: '/manual/{id}/mockup-pages', name: 'manual_mockup_pages')]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(
        Request $request,
        Manual $manual,
    ): Response {
        return $this->render('manual_mockup_pages.html.twig', [
            'project' => $manual->project,
            'manual' => $manual,
            'pages' => $this->getManualPageList->forManual($manual->id),
            'layouts_geometry' => MockupPageLayout::exportGeometry(),
        ]);
    }
}
