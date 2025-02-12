<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Email;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Services\Security\ProjectVoter;

final class EmailsController extends AbstractController
{
    #[Route(path: '/project/{id}/emails', name: 'emails_list')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(Project $project): Response
    {
        return $this->render('emails.html.twig', [
            'project' => $project,
        ]);
    }
}
