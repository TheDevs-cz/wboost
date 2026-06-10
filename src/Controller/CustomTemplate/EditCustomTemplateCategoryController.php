<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\CustomTemplate;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\CustomTemplateCategory;
use WBoost\Web\FormData\CustomTemplateCategoryFormData;
use WBoost\Web\FormType\CustomTemplateCategoryFormType;
use WBoost\Web\Message\CustomTemplate\EditCustomTemplateCategory;
use WBoost\Web\Services\Security\CustomTemplateCategoryVoter;

final class EditCustomTemplateCategoryController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/custom-template-category/{categoryId}/edit', name: 'edit_custom_template_category')]
    #[IsGranted(CustomTemplateCategoryVoter::EDIT, 'category')]
    public function __invoke(
        #[MapEntity(id: 'categoryId')]
        CustomTemplateCategory $category,
        Request $request,
    ): Response {
        $project = $category->project;
        $data = new CustomTemplateCategoryFormData();
        $data->name = $category->name;

        $form = $this->createForm(CustomTemplateCategoryFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditCustomTemplateCategory(
                    $category->id,
                    $data->name,
                ),
            );

            $this->addFlash('success', 'Kategorie upravena!');

            return $this->redirectToRoute('custom_template_categories', [
                'projectId' => $project->id,
            ]);
        }

        return $this->render('edit_custom_template_category.html.twig', [
            'form' => $form,
            'project' => $project,
            'category' => $category,
        ]);
    }
}
