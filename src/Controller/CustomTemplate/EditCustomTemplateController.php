<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\CustomTemplate;

use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\CustomTemplate;
use WBoost\Web\FormData\CustomTemplateFormData;
use WBoost\Web\FormType\CustomTemplateFormType;
use WBoost\Web\Message\CustomTemplate\EditCustomTemplate;
use WBoost\Web\Query\GetCustomTemplateCategories;
use WBoost\Web\Services\Security\CustomTemplateVoter;

final class EditCustomTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private GetCustomTemplateCategories $getCustomTemplateCategories,
    ) {
    }

    #[Route(path: '/custom-template/{templateId}/edit', name: 'edit_custom_template')]
    #[IsGranted(CustomTemplateVoter::EDIT, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        CustomTemplate $template,
        Request $request,
    ): Response {
        $project = $template->project;
        $categories = $this->getCustomTemplateCategories->allForProject($project->id);
        $data = new CustomTemplateFormData();
        $data->name = $template->name;
        $data->category = $template->category?->id->toString();
        $form = $this->createForm(CustomTemplateFormType::class, $data, [
            'categories' => $categories,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $categoryId = $data->category !== null ? Uuid::fromString($data->category) : null;

            $this->bus->dispatch(
                new EditCustomTemplate(
                    $template->id,
                    $categoryId,
                    $data->name,
                    $data->image,
                ),
            );

            $this->addFlash('success', 'Šablona upravena!');

            return $this->redirectToRoute('custom_templates', [
                'projectId' => $project->id,
            ]);
        }

        return $this->render('edit_custom_template.html.twig', [
            'form' => $form,
            'project' => $project,
            'template' => $template,
        ]);
    }
}
