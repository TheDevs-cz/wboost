<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\FlyerTemplateVariant;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Message\Image\UploadFile;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\FileSource;

/**
 * Shared "upload your own image for a placeholder" logic behind both the API
 * (OAuth) and the web-fill (session) upload endpoints. The caller authorises
 * the variant first; this validates the placeholder + target folder (one the
 * designer allowed) and stores the file.
 */
readonly final class PlaceholderImageUploader
{
    public function __construct(
        private MessageBusInterface $bus,
        private ProvideIdentity $provideIdentity,
        private FileUploadRepository $fileUploadRepository,
        private PlaceholderAllowedDirectories $allowedDirectories,
        private UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * @return array{id: string, url: string, directoryId: string}
     */
    public function upload(
        SocialNetworkTemplateVariant|FlyerTemplateVariant $variant,
        string $inputId,
        UploadedFile $file,
        null|string $requestedDirectoryId,
    ): array {
        $input = $this->findImageInput($variant, $inputId);
        if ($input === null) {
            throw new NotFoundHttpException();
        }

        $project = $variant->template->project;
        $directoryId = $this->resolveTargetDirectory($input, $project, $requestedDirectoryId);

        $fileId = $this->provideIdentity->next();

        $this->bus->dispatch(new UploadFile(
            $fileId,
            $project->id,
            FileSource::ProjectImage,
            $file,
            $directoryId,
        ));

        $upload = $this->fileUploadRepository->get($fileId);

        return [
            'id' => $upload->id->toString(),
            'url' => $this->uploaderHelper->getPublicPath($upload->path),
            'directoryId' => $directoryId->toString(),
        ];
    }

    private function findImageInput(SocialNetworkTemplateVariant|FlyerTemplateVariant $variant, string $inputId): null|EditorImageInput
    {
        foreach ($variant->imageInputs as $input) {
            if ($input->inputId === $inputId) {
                return $input;
            }
        }

        return null;
    }

    /**
     * The requested target folder must be one the slot allows; with none
     * requested we fall back to the slot's first allowed folder. An empty
     * allow-list means unrestricted (every project folder is allowed) — see
     * {@see PlaceholderAllowedDirectories}. Only a project with no gallery
     * folder at all leaves nowhere to upload, which the admin editor flags to
     * the designer up front.
     */
    private function resolveTargetDirectory(EditorImageInput $input, Project $project, null|string $requested): UuidInterface
    {
        $allowed = $this->allowedDirectories->resolve($input, $project->id);

        if ($requested !== null && $requested !== '') {
            foreach ($allowed as $directory) {
                if ($directory->id->toString() === $requested) {
                    return $directory->id;
                }
            }

            throw new AccessDeniedHttpException('That folder is not allowed for this placeholder.');
        }

        if ($allowed === []) {
            throw new BadRequestHttpException('This placeholder has no gallery folder to upload into — create a folder in the project gallery first.');
        }

        return $allowed[0]->id;
    }
}
