<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Entity\User;
use WBoost\Web\Query\GetExportVersions;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\TemplateExportVersionRepository;
use WBoost\Web\Services\Meta\GetFacebookDestinations;
use WBoost\Web\Services\Security\TemplateVariantVoter;
use WBoost\Web\Services\Template\ExportVersionSeeder;
use WBoost\Web\Services\UploaderHelper;

final class TemplateVariantExportController extends AbstractController
{
    public function __construct(
        readonly private GetFacebookDestinations $destinations,
        readonly private GetFonts $getFonts,
        readonly private UploaderHelper $uploaderHelper,
        readonly private GetExportVersions $getExportVersions,
        readonly private TemplateExportVersionRepository $versionRepository,
        readonly private ExportVersionSeeder $versionSeeder,
    ) {
    }

    #[Route(path: '/template-variant/{variantId}/export', name: 'template_variant_export')]
    #[IsGranted(TemplateVariantVoter::VIEW, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        TemplateVariant $variant,
        #[CurrentUser] User $user,
        Request $request,
    ): Response {
        // The user-fill UI is the `Template:VariantFiller` Live Component; the
        // preview renders server-side through the same Gotenberg pipeline as
        // the API export endpoint.
        $template = $variant->template;

        // `?version=<id>` loads a stored export version ("Historie exportů"):
        // the whole fill state seeds from its snapshot. An id that is invalid,
        // gone (pruned) or belongs to another variant is silently ignored —
        // stale history links must land on a working page.
        $loadedVersion = $this->resolveVersion($request, $variant);
        $seed = $loadedVersion !== null
            ? $this->versionSeeder->forVariant($loadedVersion, $variant)
            : ['textValues' => [], 'hiddenValues' => [], 'fontValues' => [], 'imageValues' => []];

        return $this->render('template_variant_export.html.twig', [
            'project' => $template->project,
            'template' => $template,
            'variant' => $variant,
            'facebookConnected' => $this->destinations->connectedAccount($user) !== null,
            // The shared classic scripts + @font-face declarations load on
            // THIS page (the deferred Live component cannot execute scripts).
            'font_faces' => $this->fontFaces($variant),
            'export_versions' => $this->getExportVersions->forVariant($variant->id),
            'loaded_version' => $loadedVersion,
            'seed_text_values' => $seed['textValues'],
            'seed_hidden_values' => $seed['hiddenValues'],
            'seed_font_values' => $seed['fontValues'],
            'seed_image_values' => $seed['imageValues'],
        ]);
    }

    private function resolveVersion(Request $request, TemplateVariant $variant): null|TemplateExportVersion
    {
        $versionId = $request->query->getString('version');

        if ($versionId === '' || !Uuid::isValid($versionId)) {
            return null;
        }

        $version = $this->versionRepository->find(Uuid::fromString($versionId));

        if ($version === null || $version->variant === null || !$version->variant->id->equals($variant->id)) {
            return null;
        }

        return $version;
    }

    /**
     * @return list<array{family: string, url: string}>
     */
    private function fontFaces(TemplateVariant $variant): array
    {
        $result = [];
        foreach ($this->getFonts->allForProject($variant->template->project->id) as $font) {
            foreach ($font->faces as $fontFace) {
                $result[] = [
                    'family' => $font->faceFamily($fontFace),
                    'url' => $this->uploaderHelper->getPublicPath($fontFace->filePath),
                ];
            }
        }

        return $result;
    }
}
