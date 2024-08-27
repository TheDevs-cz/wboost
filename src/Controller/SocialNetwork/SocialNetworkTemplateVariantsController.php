<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\SocialNetworkTemplate;
use WBoost\Web\Services\Security\SocialNetworkTemplateVoter;
use WBoost\Web\Value\TemplateDimension;

final class SocialNetworkTemplateVariantsController extends AbstractController
{
    #[Route(path: '/social-network-template/{templateId}/variants', name: 'social_network_template_variants')]
    #[IsGranted(SocialNetworkTemplateVoter::VIEW, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        SocialNetworkTemplate $template,
    ): Response {
        return $this->render('social_network_template_variants.html.twig', [
            'project' => $template->project,
            'template' => $template,
            'variants' => $template->variants(),
            'dimensions' => TemplateDimension::cases(),
        ]);
    }
}
