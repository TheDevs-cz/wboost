<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\CustomTemplate;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\FormData\CustomTemplateFormData;
use WBoost\Web\FormType\CustomTemplateFormType;
use WBoost\Web\Message\CustomTemplate\AddCustomTemplate;
use WBoost\Web\Query\GetCustomTemplateCategories;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;

final class AddCustomTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
        readonly private GetCustomTemplateCategories $getCustomTemplateCategories,
    ) {
    }

    #[Route(path: '/project/{id}/add-custom-template', name: 'add_custom_template')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[CurrentUser] UserInterface $user,
        Request $request,
        Project $project,
    ): Response {
        $categories = $this->getCustomTemplateCategories->allForProject($project->id);
        $data = new CustomTemplateFormData();
        $form = $this->createForm(CustomTemplateFormType::class, $data, [
            'categories' => $categories,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $templateId = $this->provideIdentity->next();
            $categoryId = $data->category !== null ? Uuid::fromString($data->category) : null;

            $this->bus->dispatch(
                new AddCustomTemplate(
                    $project->id,
                    $templateId,
                    $categoryId,
                    $data->name,
                    $data->image,
                ),
            );

            return $this->redirectToRoute('custom_template_variants', [
                'templateId' => $templateId,
            ]);
        }

        return $this->render('add_custom_template.html.twig', [
            'form' => $form,
            'project' => $project,
        ]);
    }
}
