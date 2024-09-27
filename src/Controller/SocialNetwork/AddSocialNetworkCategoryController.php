<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\FormData\SocialNetworkCategoryFormData;
use WBoost\Web\FormType\SocialNetworkCategoryFormType;
use WBoost\Web\Message\SocialNetwork\AddSocialNetworkCategory;
use WBoost\Web\Services\Security\ProjectVoter;

final class AddSocialNetworkCategoryController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/project/{id}/add-social-network-category', name: 'add_social_network_category')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[CurrentUser] UserInterface $user,
        Request $request,
        Project $project,
    ): Response {
        $data = new SocialNetworkCategoryFormData();
        $form = $this->createForm(SocialNetworkCategoryFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new AddSocialNetworkCategory(
                    $project->id,
                    $data->name,
                ),
            );

            return $this->redirectToRoute('social_network_categories', [
                'projectId' => $project->id,
            ]);
        }

        return $this->render('add_social_network_category.html.twig', [
            'form' => $form,
            'project' => $project,
        ]);
    }
}
