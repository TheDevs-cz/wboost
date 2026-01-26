<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Message\SocialNetwork\SortSocialNetworkTemplates;
use WBoost\Web\Services\Security\ProjectVoter;

final class SortSocialNetworkTemplatesController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/sort-social-network-templates/{projectId}', name: 'sort_social_network_templates')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
        Request $request,
    ): JsonResponse
    {
        /** @var array{sorted?: array<string>} $data */
        $data = json_decode($request->getContent(), true);
        $sorted = $data['sorted'] ?? [];

        $this->bus->dispatch(
            new SortSocialNetworkTemplates($sorted)
        );

        return new JsonResponse(['status' => 'success']);
    }
}
