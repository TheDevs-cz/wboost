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
 * Session-authed "upload your own image during fill" for the web fill page —
 * the in-browser counterpart of the OAuth API upload endpoint. Lets a user with
 * VIEW on the variant (owner or a shared collaborator) add a picture into a
 * folder the designer opened for the placeholder, returning JSON the
 * `variant-image-fill` controller drops straight onto the canvas.
 */
final class SocialNetworkTemplateVariantPlaceholderUploadWebController extends AbstractController
{
    public function __construct(
        private readonly PlaceholderImageUploader $uploader,
    ) {
    }

    #[Route(
        path: '/social-network-template-variant/{variantId}/placeholders/{inputId}/upload',
        name: 'social_network_variant_placeholder_upload',
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
