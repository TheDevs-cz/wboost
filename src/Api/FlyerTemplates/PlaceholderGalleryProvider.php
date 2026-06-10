<?php

declare(strict_types=1);

namespace WBoost\Web\Api\FlyerTemplates;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\FlyerTemplateVariantNotFound;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Repository\FlyerTemplateVariantRepository;
use WBoost\Web\Services\Security\FlyerTemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\FileSource;

/**
 * Lists the gallery images a consumer may drop into one flyer image
 * placeholder: scoped to the variant + placeholder, restricted to the folders
 * the designer allowed for that slot. Access mirrors the export endpoint
 * (variant VIEW).
 *
 * @implements ProviderInterface<PlaceholderGalleryImageResponse>
 */
final readonly class PlaceholderGalleryProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private FlyerTemplateVariantRepository $variantRepository,
        private PlaceholderAllowedDirectories $allowedDirectories,
        private FileUploadRepository $fileUploadRepository,
        private UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     * @return list<PlaceholderGalleryImageResponse>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        if (!$this->security->getUser() instanceof User) {
            throw new AuthenticationException();
        }

        $variantId = $uriVariables['variantId'] ?? null;
        if (!is_string($variantId) || !Uuid::isValid($variantId)) {
            throw new BadRequestHttpException('Invalid variant id.');
        }

        $inputId = $uriVariables['inputId'] ?? null;
        if (!is_string($inputId)) {
            throw new BadRequestHttpException('Invalid input id.');
        }

        try {
            $variant = $this->variantRepository->get(Uuid::fromString($variantId));
        } catch (FlyerTemplateVariantNotFound) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(FlyerTemplateVariantVoter::VIEW, $variant)) {
            throw new AccessDeniedHttpException();
        }

        $input = null;
        foreach ($variant->imageInputs as $candidate) {
            if ($candidate->inputId === $inputId) {
                $input = $candidate;
                break;
            }
        }

        if ($input === null) {
            throw new NotFoundHttpException();
        }

        $project = $variant->template->project;

        // Resolve the slot's allowed folders (empty allow-list = every project
        // folder; a folder deleted after the designer picked it drops out).
        $allowedDirectories = [];
        foreach ($this->allowedDirectories->resolve($input, $project->id) as $directory) {
            $allowedDirectories[$directory->id->toString()] = $directory;
        }

        if ($allowedDirectories === []) {
            return [];
        }

        $files = $this->fileUploadRepository->listByProjectSourceAndDirectories(
            $project->id,
            FileSource::ProjectImage,
            array_map(static fn (FileDirectory $directory): UuidInterface => $directory->id, array_values($allowedDirectories)),
        );

        return array_map(
            function (FileUpload $file) use ($allowedDirectories): PlaceholderGalleryImageResponse {
                $directoryId = $file->directory?->id->toString() ?? '';

                return new PlaceholderGalleryImageResponse(
                    id: $file->id->toString(),
                    url: $this->uploaderHelper->getPublicPath($file->path),
                    directoryId: $directoryId,
                    directoryName: $allowedDirectories[$directoryId]->name ?? '',
                    uploadedAt: $file->uploadedAt,
                );
            },
            $files,
        );
    }
}
