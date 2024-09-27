<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\SocialNetworkTemplate;
use WBoost\Web\FormData\SocialNetworkTemplateFormData;
use WBoost\Web\FormType\SocialNetworkTemplateFormType;
use WBoost\Web\Message\SocialNetwork\EditSocialNetworkTemplate;
use WBoost\Web\Query\GetSocialNetworkCategories;
use WBoost\Web\Services\Security\SocialNetworkTemplateVoter;

final class EditSocialNetworkTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private GetSocialNetworkCategories $getSocialNetworkCategories,
    ) {
    }

    #[Route(path: '/social-network-template/{templateId}/edit', name: 'edit_social_network_template')]
    #[IsGranted(SocialNetworkTemplateVoter::EDIT, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        SocialNetworkTemplate $template,
        Request $request,
    ): Response {
        $project = $template->project;
        $categories = $this->getSocialNetworkCategories->allForProject($project->id);
        $data = new SocialNetworkTemplateFormData();
        $data->name = $template->name;
        $data->category = $template->category?->id->toString();
        $form = $this->createForm(SocialNetworkTemplateFormType::class, $data, [
            'categories' => $categories,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $categoryId = $data->category !== null ? Uuid::fromString($data->category) : null;

            $this->bus->dispatch(
                new EditSocialNetworkTemplate(
                    $template->id,
                    $categoryId,
                    $data->name,
                    $data->image,
                ),
            );

            $this->addFlash('success', 'Šablona upravena!');

            return $this->redirectToRoute('social_network_templates', [
                'projectId' => $project->id,
            ]);
        }

        return $this->render('edit_social_network_template.html.twig', [
            'form' => $form,
            'project' => $project,
            'template' => $template,
        ]);
    }
}
