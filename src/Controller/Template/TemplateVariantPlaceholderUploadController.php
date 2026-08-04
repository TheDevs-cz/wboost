<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\Security\TemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\PlaceholderImageUploader;

/**
 * Public API parity for "upload your own image during fill" on template variants:
 * store an image into one of the folders the designer allowed for a
 * placeholder, returning the new gallery image id to reference in the export
 * `images` map.
 *
 * Secured by the `^/api` OAuth2 firewall plus the variant VIEW voter (same
 * visibility as export). The web-session counterpart is
 * {@see TemplateVariantPlaceholderUploadWebController}.
 *
 * Canonical path plus two deprecated aliases (the former custom-template and
 * social-network module paths) hitting the same unified data.
 */
final class TemplateVariantPlaceholderUploadController extends AbstractController
{
    public function __construct(
        private readonly PlaceholderImageUploader $uploader,
    ) {
    }

    #[Route(
        path: '/api/template-variants/{variantId}/placeholders/{inputId}/images',
        name: 'api_template_variant_placeholder_upload',
        methods: ['POST'],
    )]
    #[Route(
        path: '/api/custom-template-variants/{variantId}/placeholders/{inputId}/images',
        name: 'api_template_variant_placeholder_upload_legacy_custom',
        methods: ['POST'],
    )]
    #[Route(
        path: '/api/social-network-template-variants/{variantId}/placeholders/{inputId}/images',
        name: 'api_template_variant_placeholder_upload_legacy_social',
        methods: ['POST'],
    )]
    #[IsGranted(TemplateVariantVoter::VIEW, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        TemplateVariant $variant,
        string $inputId,
        Request $request,
    ): Response {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('Missing "file" upload.');
        }

        return $this->json($this->uploader->upload(
            $variant,
            $inputId,
            $file,
            $request->request->has('directoryId') ? (string) $request->request->get('directoryId') : null,
        ));
    }
}
