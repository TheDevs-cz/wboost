<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Flyer;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\FlyerTemplate;
use WBoost\Web\Services\Security\FlyerTemplateVoter;

final class FlyerTemplateVariantsController extends AbstractController
{
    #[Route(path: '/flyer-template/{templateId}/variants', name: 'flyer_template_variants')]
    #[IsGranted(FlyerTemplateVoter::VIEW, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        FlyerTemplate $template,
    ): Response {
        return $this->render('flyer_template_variants.html.twig', [
            'project' => $template->project,
            'template' => $template,
            'variants' => $template->variants(),
        ]);
    }
}
