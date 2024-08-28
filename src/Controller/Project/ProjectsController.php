<?php
declare(strict_types=1);

namespace WBoost\Web\Controller\Project;

use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WBoost\Web\Entity\User;
use WBoost\Web\Query\GetProjects;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectsController extends AbstractController
{
    public function __construct(
        readonly private GetProjects $getProjects,
    ) {
    }

    #[Route(path: '/projects', name: 'projects')]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        $projects = $this->getProjects->allForUser($user->id);

        // For read-only users redirect directly to the project
        if (count($projects) === 1 && !$this->isGranted('ROLE_DESIGNER')) {
            return $this->redirectToRoute('project_dashboard', [
                'id' => $projects[array_key_first($projects)]->id,
            ]);
        }

        return $this->render('projects.html.twig', [
            'projects' => $projects,
        ]);
    }
}
