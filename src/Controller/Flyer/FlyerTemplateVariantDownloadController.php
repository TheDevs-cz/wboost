<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Flyer;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\FlyerTemplateVariant;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Security\FlyerTemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\ResolveImageOverrides;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;

/**
 * The user-fill page is the `Flyer:VariantFiller` Live Component; its export
 * button is a regular form POST to this route (a plain form with
 * `data-turbo="false"` lets the browser handle the PNG natively via
 * Content-Disposition: attachment — see the social network counterpart for the
 * full reasoning).
 */
final class FlyerTemplateVariantDownloadController extends AbstractController
{
    public function __construct(
        private readonly TemplateVariantImageRendererInterface $renderer,
        private readonly ResolveTextOverrides $resolveTextOverrides,
        private readonly ResolveImageOverrides $resolveImageOverrides,
    ) {
    }

    #[Route(
        path: '/flyer-template-variant/{variantId}/download',
        name: 'flyer_template_variant_download',
        methods: ['POST'],
    )]
    #[IsGranted(FlyerTemplateVariantVoter::VIEW, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        FlyerTemplateVariant $variant,
        Request $request,
    ): Response {
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

        $overrides = $this->resolveTextOverrides->resolve($variant->inputs, $providedValues);
        $imageOverrides = $this->resolveImageOverrides->resolve(
            $variant->imageInputs,
            $variant->template->project->id,
            $this->parseImageValues($request),
        );

        $response = $this->renderer->render($variant, $overrides, $imageOverrides);
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="%s.png"',
            $variant->id->toString(),
        ));

        return $response;
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
