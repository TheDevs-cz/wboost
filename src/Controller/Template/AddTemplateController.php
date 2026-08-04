<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

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
use WBoost\Web\FormData\TemplateFormData;
use WBoost\Web\FormType\TemplateFormType;
use WBoost\Web\Message\Template\AddTemplate;
use WBoost\Web\Query\GetTemplateCategories;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;

final class AddTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
        readonly private GetTemplateCategories $getTemplateCategories,
    ) {
    }

    #[Route(path: '/project/{id}/add-template', name: 'add_template')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[CurrentUser] UserInterface $user,
        Request $request,
        Project $project,
    ): Response {
        $categories = $this->getTemplateCategories->allForProject($project->id);
        $data = new TemplateFormData();
        $form = $this->createForm(TemplateFormType::class, $data, [
            'categories' => $categories,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $templateId = $this->provideIdentity->next();
            $categoryId = $data->category !== null ? Uuid::fromString($data->category) : null;

            $this->bus->dispatch(
                new AddTemplate(
                    $project->id,
                    $templateId,
                    $categoryId,
                    $data->name,
                    $data->image,
                ),
            );

            return $this->redirectToRoute('template_variants', [
                'templateId' => $templateId,
            ]);
        }

        return $this->render('add_template.html.twig', [
            'form' => $form,
            'project' => $project,
        ]);
    }
}
