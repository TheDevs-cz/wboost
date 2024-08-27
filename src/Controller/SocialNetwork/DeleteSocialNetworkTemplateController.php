<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\SocialNetworkTemplate;
use WBoost\Web\Message\SocialNetwork\DeleteSocialNetworkTemplate;
use WBoost\Web\Services\Security\SocialNetworkTemplateVoter;

final class DeleteSocialNetworkTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/social-network-template/{templateId}/delete', name: 'delete_social_network_template')]
    #[IsGranted(SocialNetworkTemplateVoter::EDIT, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        SocialNetworkTemplate $template,
    ): Response {
        $project = $template->project;

        $this->bus->dispatch(
            new DeleteSocialNetworkTemplate(
                $template->id,
            ),
        );

        $this->addFlash('success', 'Šablona smazána!');

        return $this->redirectToRoute('social_network_templates', [
            'projectId' => $project->id,
        ]);
    }
}
