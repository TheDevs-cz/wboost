<?php
declare(strict_types=1);

namespace WBoost\Web\Controller\Project;

use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WBoost\Web\Entity\User;
use WBoost\Web\Query\GetProjectAvatars;
use WBoost\Web\Query\GetProjects;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectsController extends AbstractController
{
    public function __construct(
        readonly private GetProjects $getProjects,
        readonly private GetProjectAvatars $getProjectAvatars,
    ) {
    }

    #[Route(path: '/projects', name: 'projects')]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        // Admins manage every project from the normal list (owner shown, non-owned
        // styled distinctly); everyone else sees only owned + shared.
        $projects = $this->isGranted(User::ROLE_ADMIN)
            ? $this->getProjects->all()
            : $this->getProjects->allForUser($user->id);

        // For read-only users redirect directly to the project
        if (count($projects) === 1 && !$this->isGranted('ROLE_DESIGNER')) {
            return $this->redirectToRoute('project_dashboard', [
                'id' => $projects[array_key_first($projects)]->id,
            ]);
        }

        // One list, ordered by relevance: my own projects, then projects shared
        // directly with me, then (admins only) everyone else's. The card styles
        // the tiers apart; there are no section headings.
        $own = [];
        $shared = [];
        $others = [];

        foreach ($projects as $project) {
            if ($project->owner->id->equals($user->id)) {
                $own[] = $project;
            } elseif ($project->getUserSharingLevel($user) !== null) {
                $shared[] = $project;
            } else {
                $others[] = $project;
            }
        }

        return $this->render('projects.html.twig', [
            'projects' => [...$own, ...$shared, ...$others],
            'avatars' => $this->getProjectAvatars->forProjects($projects),
        ]);
    }
}
