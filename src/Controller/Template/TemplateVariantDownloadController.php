<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\ReleaseSessionLock;
use WBoost\Web\Services\Security\TemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\ResolveImageOverrides;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Services\Usage\RecordExportUsage;
use WBoost\Web\Value\ExportChannel;

/**
 * The user-fill page is the `Template:VariantFiller` Live Component; its export
 * button is a regular form POST to this route (a plain form with
 * `data-turbo="false"` lets the browser handle the PNG natively via
 * Content-Disposition: attachment — see the social network counterpart for the
 * full reasoning).
 */
final class TemplateVariantDownloadController extends AbstractController
{
    public function __construct(
        private readonly TemplateVariantImageRendererInterface $renderer,
        private readonly ResolveTextOverrides $resolveTextOverrides,
        private readonly ResolveRichTextOptions $resolveRichTextOptions,
        private readonly ResolveImageOverrides $resolveImageOverrides,
        private readonly RecordExportUsage $recordExportUsage,
        private readonly ReleaseSessionLock $releaseSessionLock,
    ) {
    }

    #[Route(
        path: '/template-variant/{variantId}/download',
        name: 'template_variant_download',
        // GET only bounces back to the fill page — see the group export
        // controller for why a download URL ever gets visited directly.
        methods: ['GET', 'POST'],
    )]
    #[IsGranted(TemplateVariantVoter::VIEW, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        TemplateVariant $variant,
        Request $request,
    ): Response {
        if (!$request->isMethod(Request::METHOD_POST)) {
            return $this->redirectToRoute('template_variant_export', ['variantId' => $variant->id]);
        }

        $rawTextValues = $request->request->all('textValues');
        $rawHiddenValues = $request->request->all('hiddenValues');

        /** @var array<string, array{value?: string, hide?: bool}> $providedValues */
        $providedValues = [];

        foreach ($rawTextValues as $inputId => $value) {
            if (!is_string($value)) {
                continue;
            }
            $providedValues[(string) $inputId] = ['value' => $value];
        }

        // HTML checkboxes only appear in $request->request when checked, so
        // every key present here represents an explicit "hide" selection.
        foreach ($rawHiddenValues as $inputId => $_) {
            $key = (string) $inputId;
            if (!isset($providedValues[$key])) {
                $providedValues[$key] = [];
            }
            $providedValues[$key]['hide'] = true;
        }

        // Before the resolves, not just the render: ResolveImageOverrides
        // already reads image bytes from Minio, and none of it needs the
        // session.
        $this->releaseSessionLock->release($request);

        try {
            $overrides = $this->resolveTextOverrides->resolve(
                $variant->inputs,
                $providedValues,
                truncateOverflow: true,
                richTextOptions: $this->resolveRichTextOptions->forVariant($variant),
            );
            $imageOverrides = $this->resolveImageOverrides->resolve(
                $variant->imageInputs,
                $variant->template->project->id,
                $this->parseImageValues($request),
            );
        } catch (BadRequestHttpException $e) {
            // Unrenderable fill values are the user's input, not a crash — show
            // the reason on a page they can go BACK from with the form intact.
            return $this->renderFailed($variant, $e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        try {
            $response = $this->renderer->render($variant, $overrides, $imageOverrides);
        } catch (TemplateRenderUnavailable) {
            return $this->renderFailed(
                $variant,
                'Vykreslovací služba je přetížená a neodpověděla včas. Zkuste stažení prosím znovu za chvíli.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="%s.png"',
            $variant->id->toString(),
        ));

        $this->recordExportUsage->record($variant, ExportChannel::Web);

        return $response;
    }

    private function renderFailed(TemplateVariant $variant, string $reason, int $status): Response
    {
        return $this->render('export_failed.html.twig', [
            'project' => $variant->template->project,
            'menu_item' => 'templates',
            'reason' => $reason,
            'back_url' => $this->generateUrl('template_variant_export', ['variantId' => $variant->id]),
        ], new Response(status: $status));
    }

    /**
     * Normalise the posted `images[inputId][...]` fields into the shape
     * ResolveImageOverrides expects. The fill UI writes one group per filled
     * placeholder; HTML form values arrive as strings, so numeric transform
     * fields are cast to float and `hide` to bool before validation.
     *
     * @return array<string, mixed>
     */
    private function parseImageValues(Request $request): array
    {
        /** @var array<string, mixed> $raw */
        $raw = $request->request->all('images');
        $provided = [];

        foreach ($raw as $inputId => $value) {
            $key = (string) $inputId;

            // Shorthand: images[inputId] = "<imageId>".
            if (is_string($value)) {
                if ($value !== '') {
                    $provided[$key] = $value;
                }
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            $entry = [];

            $imageId = $value['imageId'] ?? null;
            if (is_string($imageId) && $imageId !== '') {
                $entry['imageId'] = $imageId;
            }

            foreach (['scale', 'offsetX', 'offsetY', 'rotation'] as $field) {
                $candidate = $value[$field] ?? null;
                if (is_numeric($candidate)) {
                    $entry[$field] = (float) $candidate;
                }
            }

            // HTML checkbox: present (e.g. "1"/"true") = hide, absent = keep.
            if (isset($value['hide'])) {
                $entry['hide'] = in_array($value['hide'], ['1', 'true', true, 1], true);
            }

            if ($entry !== []) {
                $provided[$key] = $entry;
            }
        }

        return $provided;
    }
}
