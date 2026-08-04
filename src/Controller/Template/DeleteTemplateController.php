<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Template;
use WBoost\Web\Message\Template\DeleteTemplate;
use WBoost\Web\Services\Security\TemplateVoter;

final class DeleteTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/template/{templateId}/delete', name: 'delete_template')]
    #[IsGranted(TemplateVoter::EDIT, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        Template $template,
    ): Response {
        $project = $template->project;

        $this->bus->dispatch(
            new DeleteTemplate(
                $template->id,
            ),
        );

        $this->addFlash('success', 'Šablona smazána!');

        return $this->redirectToRoute('templates', [
            'projectId' => $project->id,
        ]);
    }
}
