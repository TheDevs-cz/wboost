<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\MessageHandler\Template\RecordTemplateExportVersionHandler;
use WBoost\Web\Query\GetExportVersions;
use WBoost\Web\Services\Security\TemplateVariantVoter;
use WBoost\Web\Services\Template\SummarizeExportVersion;

/**
 * The full export history of one variant ("Historie exportů" page): every
 * stored version, pinned first, with rename / pin controls and a "Načíst"
 * link back into the fill page. The fill page's dropdown only shows the
 * pinned ones plus a handful of recent — this is where the rest lives.
 */
final class TemplateVariantExportHistoryController extends AbstractController
{
    public function __construct(
        readonly private GetExportVersions $getExportVersions,
        readonly private SummarizeExportVersion $summarize,
    ) {
    }

    #[Route(path: '/template-variant/{variantId}/export-history', name: 'template_variant_export_history', methods: ['GET'])]
    #[IsGranted(TemplateVariantVoter::VIEW, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        TemplateVariant $variant,
    ): Response {
        $template = $variant->template;
        $history = $this->getExportVersions->forVariant($variant->id);

        return $this->render('template_export_history.html.twig', [
            'project' => $template->project,
            'template' => $template,
            'variant' => $variant,
            'group' => null,
            'subject_label' => sprintf(
                'Varianta %s (%d×%d px)',
                $variant->dimension->label(),
                $variant->dimension->width(),
                $variant->dimension->height(),
            ),
            'history' => $history,
            'history_cap' => RecordTemplateExportVersionHandler::MAX_VERSIONS,
            'summaries' => $this->summarize->forVersions($history->all(), [$variant]),
            'load_route_name' => 'template_variant_export',
            'load_route_params' => ['variantId' => $variant->id->toString()],
            'fill_url' => $this->generateUrl('template_variant_export', ['variantId' => $variant->id]),
        ]);
    }
}
