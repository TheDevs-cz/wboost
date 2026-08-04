<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemReader;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\Security\TemplateVariantVoter;

/**
 * Public API: stream a template variant's thumbnail — its cached preview render
 * when one exists, otherwise the background image — from the upload (Minio)
 * filesystem. API consumers never reach the object store directly.
 *
 * Canonical path plus two deprecated aliases (the former custom-template and
 * social-network module paths) serving the same unified data.
 */
final class TemplateVariantThumbnailController extends AbstractController
{
    public function __construct(
        #[Autowire(service: 'oneup_flysystem.minio_filesystem')]
        private readonly FilesystemReader $filesystem,
    ) {
    }

    #[Route(
        path: '/api/template-variants/{variantId}/thumbnail',
        name: 'api_template_variant_thumbnail',
        methods: ['GET'],
    )]
    #[Route(
        path: '/api/custom-template-variants/{variantId}/thumbnail',
        name: 'api_template_variant_thumbnail_legacy_custom',
        methods: ['GET'],
    )]
    #[Route(
        path: '/api/social-network-template-variants/{variantId}/thumbnail',
        name: 'api_template_variant_thumbnail_legacy_social',
        methods: ['GET'],
    )]
    #[IsGranted(TemplateVariantVoter::VIEW, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        TemplateVariant $variant,
    ): Response {
        $path = $variant->previewImagePath ?? $variant->backgroundImage;

        // A layer-mode variant may have neither a saved preview nor a
        // background — there is simply no thumbnail to serve.
        if ($path === null) {
            throw new NotFoundHttpException();
        }

        try {
            $contents = $this->filesystem->read($path);
        } catch (FilesystemException) {
            throw new NotFoundHttpException();
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'png';

        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => $this->imageMimeType($path),
            'Content-Disposition' => sprintf('inline; filename="%s.%s"', $variant->id->toString(), $extension),
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function imageMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };
    }
}
