<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\SocialNetworkCategory;
use WBoost\Web\FormData\SocialNetworkCategoryFormData;
use WBoost\Web\FormType\SocialNetworkCategoryFormType;
use WBoost\Web\Message\SocialNetwork\EditSocialNetworkCategory;
use WBoost\Web\Services\Security\SocialNetworkCategoryVoter;

final class EditSocialNetworkCategoryController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/social-network-category/{categoryId}/edit', name: 'edit_social_network_category')]
    #[IsGranted(SocialNetworkCategoryVoter::EDIT, 'category')]
    public function __invoke(
        #[MapEntity(id: 'categoryId')]
        SocialNetworkCategory $category,
        Request $request,
    ): Response {
        $project = $category->project;
        $data = new SocialNetworkCategoryFormData();
        $data->name = $category->name;

        $form = $this->createForm(SocialNetworkCategoryFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->bus->dispatch(
                new EditSocialNetworkCategory(
                    $category->id,
                    $data->name,
                ),
            );

            $this->addFlash('success', 'Kategorie upravena!');

            return $this->redirectToRoute('social_network_categories', [
                'projectId' => $project->id,
            ]);
        }

        return $this->render('edit_social_network_category.html.twig', [
            'form' => $form,
            'project' => $project,
            'category' => $category,
        ]);
    }
}
