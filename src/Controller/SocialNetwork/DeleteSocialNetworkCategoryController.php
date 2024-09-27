<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\SocialNetworkCategory;
use WBoost\Web\Message\SocialNetwork\DeleteSocialNetworkCategory;
use WBoost\Web\Services\Security\SocialNetworkCategoryVoter;

final class DeleteSocialNetworkCategoryController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/social-network-category/{categoryId}/delete', name: 'delete_social_network_category')]
    #[IsGranted(SocialNetworkCategoryVoter::EDIT, 'category')]
    public function __invoke(
        #[MapEntity(id: 'categoryId')]
        SocialNetworkCategory $category,
    ): Response {
        $project = $category->project;

        $this->bus->dispatch(
            new DeleteSocialNetworkCategory(
                $category->id,
            ),
        );

        $this->addFlash('success', 'Kategorie smazána!');

        return $this->redirectToRoute('social_network_categories', [
            'projectId' => $project->id,
        ]);
    }
}
