<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Flyer;

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
use WBoost\Web\FormData\FlyerTemplateFormData;
use WBoost\Web\FormType\FlyerTemplateFormType;
use WBoost\Web\Message\Flyer\AddFlyerTemplate;
use WBoost\Web\Query\GetFlyerCategories;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;

final class AddFlyerTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
        readonly private GetFlyerCategories $getFlyerCategories,
    ) {
    }

    #[Route(path: '/project/{id}/add-flyer-template', name: 'add_flyer_template')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[CurrentUser] UserInterface $user,
        Request $request,
        Project $project,
    ): Response {
        $categories = $this->getFlyerCategories->allForProject($project->id);
        $data = new FlyerTemplateFormData();
        $form = $this->createForm(FlyerTemplateFormType::class, $data, [
            'categories' => $categories,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $templateId = $this->provideIdentity->next();
            $categoryId = $data->category !== null ? Uuid::fromString($data->category) : null;

            $this->bus->dispatch(
                new AddFlyerTemplate(
                    $project->id,
                    $templateId,
                    $categoryId,
                    $data->name,
                    $data->image,
                ),
            );

            return $this->redirectToRoute('flyer_template_variants', [
                'templateId' => $templateId,
            ]);
        }

        return $this->render('add_flyer_template.html.twig', [
            'form' => $form,
            'project' => $project,
        ]);
    }
}
