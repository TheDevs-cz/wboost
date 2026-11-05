<?php
declare(strict_types=1);

namespace WBoost\Web\Controller\Project;

use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Query\GetTemplates;
use WBoost\Web\Query\GetEmailSignatureTemplates;
use WBoost\Web\Query\GetFontUsage;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Query\GetManuals;
use WBoost\Web\Query\GetWeeklyMenus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Repository\ProjectColorRepository;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Value\FileSource;

final class ProjectDashboardController extends AbstractController
{
    public function __construct(
        readonly private GetManuals $getManuals,
        readonly private GetFonts $getFonts,
        readonly private GetFontUsage $getFontUsage,
        readonly private GetTemplates $getTemplates,
        readonly private GetEmailSignatureTemplates $getEmailSignatureTemplates,
        readonly private GetWeeklyMenus $getWeeklyMenus,
        readonly private FileUploadRepository $fileUploadRepository,
        readonly private ProjectColorRepository $projectColorRepository,
    ) {
    }

    #[Route(path: '/project/{id}', name: 'project_dashboard')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(Project $project): Response
    {
        // Single source since the module merge: grouped and plain templates alike.
        $templates = $this->getTemplates->allForProject($project->id, excludeDocumentPages: true);

        return $this->render('project_dashboard.html.twig', [
            'project' => $project,
            'manuals' => $this->getManuals->allForProject($project->id),
            // "Barevná paleta" chips (F7 P2): the project palette, palette order.
            'palette_colors' => $this->projectColorRepository->forProject($project->id),
            'fonts' => $this->getFonts->allForProject($project->id),
            // Templates referencing a font no project face satisfies — the
            // Fonty card wears a warning badge instead of its count.
            'missing_fonts' => $this->getFontUsage->forProject($project->id)->missing,
            'templates' => $templates,
            'emails' => $this->getEmailSignatureTemplates->allForProject($project->id),
            'weekly_menus' => $this->getWeeklyMenus->allForProject($project->id),
            'gallery_images_count' => $this->fileUploadRepository->countByProjectAndSource($project->id, FileSource::ProjectImage),
        ]);
    }
}
