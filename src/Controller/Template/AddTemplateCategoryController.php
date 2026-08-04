<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\FormData\TemplateCategoryFormData;
use WBoost\Web\FormType\TemplateCategoryFormType;
use WBoost\Web\Message\Template\AddTemplateCategory;
use WBoost\Web\Services\Security\ProjectVoter;

final class AddTemplateCategoryController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/project/{id}/add-template-category', name: 'add_template_category')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[CurrentUser] UserInterface $user,
        Request $request,
        Project $project,
    ): Response {
        $data = new TemplateCategoryFormData();
        $form = $this->createForm(TemplateCategoryFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new AddTemplateCategory(
                    $project->id,
                    $data->name,
                ),
            );

            return $this->redirectToRoute('template_categories', [
                'projectId' => $project->id,
            ]);
        }

        return $this->render('add_template_category.html.twig', [
            'form' => $form,
            'project' => $project,
        ]);
    }
}
