<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\CustomTemplate;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\FormData\CustomTemplateCategoryFormData;
use WBoost\Web\FormType\CustomTemplateCategoryFormType;
use WBoost\Web\Message\CustomTemplate\AddCustomTemplateCategory;
use WBoost\Web\Services\Security\ProjectVoter;

final class AddCustomTemplateCategoryController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/project/{id}/add-custom-template-category', name: 'add_custom_template_category')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[CurrentUser] UserInterface $user,
        Request $request,
        Project $project,
    ): Response {
        $data = new CustomTemplateCategoryFormData();
        $form = $this->createForm(CustomTemplateCategoryFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new AddCustomTemplateCategory(
                    $project->id,
                    $data->name,
                ),
            );

            return $this->redirectToRoute('custom_template_categories', [
                'projectId' => $project->id,
            ]);
        }

        return $this->render('add_custom_template_category.html.twig', [
            'form' => $form,
            'project' => $project,
        ]);
    }
}
