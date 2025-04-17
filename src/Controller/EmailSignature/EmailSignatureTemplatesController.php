<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\EmailSignature;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Query\GetEmailSignatureTemplates;
use WBoost\Web\Services\Security\ProjectVoter;

final class EmailSignatureTemplatesController extends AbstractController
{
    public function __construct(
        readonly private GetEmailSignatureTemplates $getEmailSignatureTemplates,
    ) {
    }

    #[Route(path: '/project/{id}/emails', name: 'email_signature_templates')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(Project $project): Response
    {
        return $this->render('email_signature_templates.html.twig', [
            'project' => $project,
            'email_templates' => $this->getEmailSignatureTemplates->allForProject($project->id),
        ]);
    }
}
