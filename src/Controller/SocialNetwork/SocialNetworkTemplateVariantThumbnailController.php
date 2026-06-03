<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemReader;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Services\Security\SocialNetworkTemplateVariantVoter;

/**
 * Public API: stream a variant's thumbnail — its cached preview render when one
 * exists, otherwise the background image — from the upload (Minio) filesystem.
 *
 * Exists so API consumers never need to reach the object store directly: they
 * only ever talk to the API host. The templates response exposes the URL to
 * this endpoint as `variants[].thumbnailUrl`.
 *
 * Secured by the `^/api` OAuth2 firewall plus the same VIEW voter the export
 * endpoint uses, so visibility matches `POST .../{id}/export`.
 */
final class SocialNetworkTemplateVariantThumbnailController extends AbstractController
{
    public function __construct(
        #[Autowire(service: 'oneup_flysystem.minio_filesystem')]
        private readonly FilesystemReader $filesystem,
    ) {
    }

    #[Route(
        path: '/api/social-network-template-variants/{variantId}/thumbnail',
        name: 'api_social_network_template_variant_thumbnail',
        methods: ['GET'],
    )]
    #[IsGranted(SocialNetworkTemplateVariantVoter::VIEW, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        SocialNetworkTemplateVariant $variant,
    ): Response {
        $path = $variant->previewImagePath ?? $variant->backgroundImage;

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
