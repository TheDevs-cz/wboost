<?php
declare(strict_types=1);

namespace WBoost\Web\Controller\Project;

use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\CustomTemplate;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\SocialNetworkTemplate;
use WBoost\Web\Query\GetCustomTemplates;
use WBoost\Web\Query\GetEmailSignatureTemplates;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Query\GetManuals;
use WBoost\Web\Query\GetWeeklyMenus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Query\GetSocialNetworkTemplates;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Value\FileSource;

final class ProjectDashboardController extends AbstractController
{
    public function __construct(
        readonly private GetManuals $getManuals,
        readonly private GetFonts $getFonts,
        readonly private GetSocialNetworkTemplates $getSocialNetworkTemplates,
        readonly private GetCustomTemplates $getCustomTemplates,
        readonly private GetEmailSignatureTemplates $getEmailSignatureTemplates,
        readonly private GetWeeklyMenus $getWeeklyMenus,
        readonly private FileUploadRepository $fileUploadRepository,
    ) {
    }

    #[Route(path: '/project/{id}', name: 'project_dashboard')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(Project $project): Response
    {
        $socialTemplates = $this->getSocialNetworkTemplates->allForProject($project->id);
        $customTemplates = $this->getCustomTemplates->allForProject($project->id);

        /** @var list<array{type: 'social'|'custom', template: SocialNetworkTemplate|CustomTemplate}> $recentTemplates */
        $recentTemplates = [
            ...array_map(
                static fn (SocialNetworkTemplate $template): array => ['type' => 'social', 'template' => $template],
                $socialTemplates,
            ),
            ...array_map(
                static fn (CustomTemplate $template): array => ['type' => 'custom', 'template' => $template],
                $customTemplates,
            ),
        ];

        usort(
            $recentTemplates,
            static fn (array $a, array $b): int => $b['template']->createdAt <=> $a['template']->createdAt,
        );

        return $this->render('project_dashboard.html.twig', [
            'project' => $project,
            'manuals' => $this->getManuals->allForProject($project->id),
            'fonts' => $this->getFonts->allForProject($project->id),
            'social_templates' => $socialTemplates,
            'custom_templates' => $customTemplates,
            'emails' => $this->getEmailSignatureTemplates->allForProject($project->id),
            'weekly_menus' => $this->getWeeklyMenus->allForProject($project->id),
            'gallery_images_count' => $this->fileUploadRepository->countByProjectAndSource($project->id, FileSource::ProjectImage),
            'recent_templates' => array_slice($recentTemplates, 0, 6),
        ]);
    }
}
