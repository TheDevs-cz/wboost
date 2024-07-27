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

        return $this->render('homepage.html.twig', [
            'projects' => $projects,
        ]);
    }
}
