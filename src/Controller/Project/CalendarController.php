<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Project;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Services\Security\ProjectVoter;

/**
 * Work-in-progress landing page for the upcoming Kalendáře (calendars) module.
 * The module isn't built yet — this page announces it and invites interested
 * users to reach out about beta testing. Linked from the left navigation.
 */
final class CalendarController extends AbstractController
{
    #[Route(path: '/project/{projectId}/calendars', name: 'calendars')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        return $this->render('calendars.html.twig', [
            'project' => $project,
        ]);
    }
}
