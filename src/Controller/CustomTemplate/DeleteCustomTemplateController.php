<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\CustomTemplate;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\CustomTemplate;
use WBoost\Web\Message\CustomTemplate\DeleteCustomTemplate;
use WBoost\Web\Services\Security\CustomTemplateVoter;

final class DeleteCustomTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/custom-template/{templateId}/delete', name: 'delete_custom_template')]
    #[IsGranted(CustomTemplateVoter::EDIT, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        CustomTemplate $template,
    ): Response {
        $project = $template->project;

        $this->bus->dispatch(
            new DeleteCustomTemplate(
                $template->id,
            ),
        );

        $this->addFlash('success', 'Šablona smazána!');

        return $this->redirectToRoute('custom_templates', [
            'projectId' => $project->id,
        ]);
    }
}
