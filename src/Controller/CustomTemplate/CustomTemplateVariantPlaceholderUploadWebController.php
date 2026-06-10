<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\CustomTemplate;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Services\Security\CustomTemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\PlaceholderImageUploader;

/**
 * Session-authed "upload your own image during fill" for the custom-template web fill
 * page — the in-browser counterpart of the OAuth API upload endpoint.
 */
final class CustomTemplateVariantPlaceholderUploadWebController extends AbstractController
{
    public function __construct(
        private readonly PlaceholderImageUploader $uploader,
    ) {
    }

    #[Route(
        path: '/custom-template-variant/{variantId}/placeholders/{inputId}/upload',
        name: 'custom_template_variant_placeholder_upload',
        methods: ['POST'],
    )]
    #[IsGranted(CustomTemplateVariantVoter::VIEW, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        CustomTemplateVariant $variant,
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
