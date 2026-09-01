<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Template;
use WBoost\Web\Query\GetExportVersions;
use WBoost\Web\Services\Security\TemplateVoter;

final class TemplateVariantsController extends AbstractController
{
    public function __construct(
        readonly private GetExportVersions $getExportVersions,
    ) {
    }

    #[Route(path: '/template/{templateId}/variants', name: 'template_variants')]
    #[IsGranted(TemplateVoter::VIEW, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        Template $template,
    ): Response {
        return $this->render('template_variants.html.twig', [
            'project' => $template->project,
            'template' => $template,
            'variants' => $template->variants(),
            // variantId → latest export version, for the tiles' "naposledy
            // exportováno" line + the menu's quick-load item.
            'last_exports' => $this->getExportVersions->latestForTemplateVariants($template->id),
        ]);
    }
}
