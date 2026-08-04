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
use WBoost\Web\FormData\AddTemplateFormData;
use WBoost\Web\FormType\AddTemplateFormType;
use WBoost\Web\Message\Template\AddTemplate;
use WBoost\Web\Message\Template\AddTemplateVariant;
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
        $data = new AddTemplateFormData();
        $form = $this->createForm(AddTemplateFormType::class, $data, [
            'categories' => $categories,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $templateId = $this->provideIdentity->next();
            $variantId = $this->provideIdentity->next();
            $categoryId = $data->template->category !== null ? Uuid::fromString($data->template->category) : null;

            $this->bus->dispatch(
                new AddTemplate(
                    $project->id,
                    $templateId,
                    $categoryId,
                    $data->template->name,
                    $data->template->image,
                ),
            );

            // The first variant is created in the same step — straight into
            // the editor, same as adding any further variant.
            $this->bus->dispatch(
                new AddTemplateVariant(
                    $templateId,
                    $variantId,
                    $data->variant->dimension(),
                    $data->variant->backgroundImage,
                ),
            );

            return $this->redirectToRoute('template_variant_editor', [
                'variantId' => $variantId,
            ]);
        }

        return $this->render('add_template.html.twig', [
            'form' => $form,
            'project' => $project,
        ]);
    }
}
