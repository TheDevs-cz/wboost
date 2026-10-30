<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateGroup;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Services\ReleaseSessionLock;
use WBoost\Web\Services\Security\TemplateGroupVoter;
use WBoost\Web\Services\Template\RecordExportVersion;
use WBoost\Web\Services\TemplateGroup\GroupExportArchive;
use WBoost\Web\Services\TemplateGroup\GroupFillRenderer;
use WBoost\Web\Services\Usage\RecordExportUsage;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportFillValues;
use WBoost\Web\Value\ExportFormat;

/**
 * Download ONE member variant of the group as a PNG or print PDF — the per-dimension
 * counterpart of the whole-group ZIP ({@see TemplateGroupExportController}).
 * The fill page's per-variant download buttons submit the SAME fill form via
 * `formaction`, so the unified values (and per-dimension placements) arrive
 * exactly as they would for the ZIP; only the target differs.
 */
final class TemplateGroupExportVariantController extends AbstractController
{
    public function __construct(
        readonly private GetTemplateGroupMembers $members,
        readonly private GroupFillRenderer $groupFillRenderer,
        readonly private RecordExportUsage $recordExportUsage,
        readonly private RecordExportVersion $recordExportVersion,
        readonly private ReleaseSessionLock $releaseSessionLock,
        readonly private GroupExportArchive $archive,
    ) {
    }

    /**
     * GET redirects back to the fill page, mirroring the ZIP export: the URL
     * only ends up in the address bar when something went wrong, and a later
     * reload/revisit must not strand the user on a 405.
     */
    #[Route(path: '/template-group/{groupId}/export/{variantId}', name: 'template_group_export_variant', methods: ['GET', 'POST'])]
    #[IsGranted(TemplateGroupVoter::VIEW, 'group')]
    public function __invoke(
        #[MapEntity(id: 'groupId')]
        TemplateGroup $group,
        string $variantId,
        Request $request,
    ): Response {
        if (!$request->isMethod(Request::METHOD_POST)) {
            return $this->redirectToRoute('template_group_fill', ['groupId' => $group->id]);
        }

        $variant = $this->resolveMemberVariant($group, $variantId);

        if ($variant === null) {
            throw $this->createNotFoundException('Variant does not belong to this group.');
        }

        $this->releaseSessionLock->release($request);

        // The pressed PNG / PDF button posts the format; PNG stays lossless,
        // the same contract as the ZIP entries. PDF = print formats only.
        $format = ExportFormat::fromRequestValue($request->request->get('format'));

        if ($format === ExportFormat::Pdf && !$variant->dimension->isPrintFormat()) {
            return $this->renderFailed($group, 'Export do PDF je k dispozici jen pro tiskové formáty (mm / cm).', Response::HTTP_BAD_REQUEST);
        }

        try {
            $bytes = $this->groupFillRenderer->renderExport(
                $format,
                $variant,
                $this->archive->documentTitle($group, $variant),
                $request->request->all('textValues'),
                $request->request->all('hiddenValues'),
                $request->request->all('images'),
                $request->request->all('imagePlacements'),
                rawFontValues: $request->request->all('fontValues'),
                rawShapeColors: $request->request->all('shapeColorValues'),
            );
        } catch (TemplateRenderUnavailable) {
            return $this->renderFailed(
                $group,
                'Vykreslovací služba je přetížená a neodpověděla včas. Zkuste export prosím znovu za chvíli.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        } catch (BadRequestHttpException $e) {
            // The fill values are not renderable — the user's input, not a
            // crash: show the reason on a page they can go BACK from with the
            // form still filled in.
            return $this->renderFailed($group, $e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $this->recordExportUsage->record($variant, ExportChannel::Web, $format);

        // The per-dimension download runs on the SAME group fill form as the
        // ZIP, so the version lands on the group (and dedupes against a later
        // ZIP export of the identical fill).
        $this->recordExportVersion->recordGroup($group, ExportChannel::Web, ExportFillValues::fromGroupWebForm(
            $request->request->all('textValues'),
            $request->request->all('hiddenValues'),
            $request->request->all('images'),
            $request->request->all('imagePlacements'),
            $request->request->all('fontValues'),
            $request->request->all('shapeColorValues'),
        ), $request->request->getString('draftVersionId'));

        $fileName = $this->archive->fileName($group, $variant, $format);

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => $format->contentType(),
            'Content-Disposition' => sprintf('attachment; filename="%s"', $fileName),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Only variants actually belonging to the group are downloadable here —
     * the same membership rule the fill preview and the group save enforce.
     */
    private function resolveMemberVariant(TemplateGroup $group, string $variantId): null|TemplateVariant
    {
        foreach ($this->members->variants($group->id) as $variant) {
            if ($variant->id->toString() === $variantId) {
                return $variant;
            }
        }

        return null;
    }

    private function renderFailed(TemplateGroup $group, string $reason, int $status): Response
    {
        return $this->render('export_failed.html.twig', [
            'project' => $group->project,
            'menu_item' => 'templates',
            'reason' => $reason,
            'back_url' => $this->generateUrl('template_group_fill', ['groupId' => $group->id]),
        ], new Response(status: $status));
    }
}
