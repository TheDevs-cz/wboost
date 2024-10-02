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
use WBoost\Web\Message\SocialNetwork\CopySocialNetworkTemplate;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\SocialNetworkTemplateVoter;

final class DuplicateSocialNetworkTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/social-network-template/{templateId}/copy', name: 'copy_social_network_template')]
    #[IsGranted(SocialNetworkTemplateVoter::EDIT, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        SocialNetworkTemplate $template,
    ): Response {
        $newId = $this->provideIdentity->next();

        $this->bus->dispatch(
            new CopySocialNetworkTemplate(
                $template->id,
                $newId,
            ),
        );

        $this->addFlash('success', 'Šablona zduplikována.');

        return $this->redirectToRoute('social_network_template_variants', [
            'templateId' => $newId,
        ]);
    }
}
