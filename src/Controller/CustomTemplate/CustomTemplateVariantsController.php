<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\CustomTemplate;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\CustomTemplate;
use WBoost\Web\Services\Security\CustomTemplateVoter;

final class CustomTemplateVariantsController extends AbstractController
{
    #[Route(path: '/custom-template/{templateId}/variants', name: 'custom_template_variants')]
    #[IsGranted(CustomTemplateVoter::VIEW, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        CustomTemplate $template,
    ): Response {
        return $this->render('custom_template_variants.html.twig', [
            'project' => $template->project,
            'template' => $template,
            'variants' => $template->variants(),
        ]);
    }
}
