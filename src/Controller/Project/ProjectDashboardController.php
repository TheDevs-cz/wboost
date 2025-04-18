<?php
declare(strict_types=1);

namespace WBoost\Web\Controller\Project;

use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Query\GetEmailSignatureTemplates;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Query\GetManuals;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Query\GetSocialNetworkTemplates;
use WBoost\Web\Services\Security\ProjectVoter;

final class ProjectDashboardController extends AbstractController
{
    public function __construct(
        readonly private GetManuals $getManuals,
        readonly private GetFonts $getFonts,
        readonly private GetSocialNetworkTemplates $getSocialNetworkTemplates,
        readonly private GetEmailSignatureTemplates $getEmailSignatureTemplates,
    ) {
    }

    #[Route(path: '/project/{id}', name: 'project_dashboard')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(Project $project): Response
    {
        return $this->render('project_dashboard.html.twig', [
            'project' => $project,
            'manuals' => $this->getManuals->allForProject($project->id),
            'fonts' => $this->getFonts->allForProject($project->id),
            'social_templates' => $this->getSocialNetworkTemplates->allForProject($project->id),
            'emails' => $this->getEmailSignatureTemplates->allForProject($project->id),
        ]);
    }
}
