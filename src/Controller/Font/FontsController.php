<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Font;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\Security\ProjectVoter;

final class FontsController extends AbstractController
{
    public function __construct(
        readonly private GetFonts $getFonts,
    ) {
    }

    #[Route(path: '/project/{id}/fonts', name: 'fonts_list')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(Project $project): Response
    {
        return $this->render('fonts.html.twig', [
            'project' => $project,
            'fonts' => $this->getFonts->allForProject($project->id),
        ]);
    }
}
