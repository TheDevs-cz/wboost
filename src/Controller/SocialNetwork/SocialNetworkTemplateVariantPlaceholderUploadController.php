<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Services\Security\SocialNetworkTemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\PlaceholderImageUploader;

/**
 * Public API parity for "upload your own image during fill": store an image into
 * one of the folders the designer allowed for a placeholder, returning the new
 * gallery image id to reference in the export `images` map.
 *
 * Secured by the `^/api` OAuth2 firewall plus the variant VIEW voter (same
 * visibility as export). The web-session counterpart is
 * {@see SocialNetworkTemplateVariantPlaceholderUploadWebController}.
 */
final class SocialNetworkTemplateVariantPlaceholderUploadController extends AbstractController
{
    public function __construct(
        private readonly PlaceholderImageUploader $uploader,
    ) {
    }

    #[Route(
        path: '/api/social-network-template-variants/{variantId}/placeholders/{inputId}/images',
        name: 'api_social_network_template_variant_placeholder_upload',
        methods: ['POST'],
    )]
    #[IsGranted(SocialNetworkTemplateVariantVoter::VIEW, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        SocialNetworkTemplateVariant $variant,
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
