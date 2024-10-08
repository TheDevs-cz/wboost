<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

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
use WBoost\Web\FormData\SocialNetworkTemplateFormData;
use WBoost\Web\FormType\SocialNetworkTemplateFormType;
use WBoost\Web\Message\SocialNetwork\AddSocialNetworkTemplate;
use WBoost\Web\Query\GetSocialNetworkCategories;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;

final class AddSocialNetworkTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
        readonly private GetSocialNetworkCategories $getSocialNetworkCategories,
    ) {
    }

    #[Route(path: '/project/{id}/add-social-network-template', name: 'add_social_network_template')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[CurrentUser] UserInterface $user,
        Request $request,
        Project $project,
    ): Response {
        $categories = $this->getSocialNetworkCategories->allForProject($project->id);
        $data = new SocialNetworkTemplateFormData();
        $form = $this->createForm(SocialNetworkTemplateFormType::class, $data, [
            'categories' => $categories,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $templateId = $this->provideIdentity->next();
            $categoryId = $data->category !== null ? Uuid::fromString($data->category) : null;

            $this->bus->dispatch(
                new AddSocialNetworkTemplate(
                    $project->id,
                    $templateId,
                    $categoryId,
                    $data->name,
                    $data->image,
                ),
            );

            return $this->redirectToRoute('social_network_template_variants', [
                'templateId' => $templateId,
            ]);
        }

        return $this->render('add_social_network_template.html.twig', [
            'form' => $form,
            'project' => $project,
        ]);
    }
}
