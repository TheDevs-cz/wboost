<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Exceptions\FileDirectoryNotFound;
use WBoost\Web\Message\Image\UploadFile;
use WBoost\Web\Repository\FileDirectoryRepository;
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
        private FileDirectoryRepository $fileDirectoryRepository,
        private UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * @return array{id: string, url: string, directoryId: string}
     */
    public function upload(
        SocialNetworkTemplateVariant $variant,
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
            FileSource::SocialNetworkImage,
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

    private function findImageInput(SocialNetworkTemplateVariant $variant, string $inputId): null|EditorImageInput
    {
        foreach ($variant->imageInputs as $input) {
            if ($input->inputId === $inputId) {
                return $input;
            }
        }

        return null;
    }

    /**
     * The requested target folder must be one the designer allowed for this
     * slot; with none requested we fall back to the slot's first allowed folder.
     * The chosen folder is re-checked to belong to the project.
     */
    private function resolveTargetDirectory(EditorImageInput $input, Project $project, null|string $requested): UuidInterface
    {
        if ($requested !== null && $requested !== '') {
            if (!in_array($requested, $input->allowedDirectoryIds, true)) {
                throw new AccessDeniedHttpException('That folder is not allowed for this placeholder.');
            }
            $candidate = $requested;
        } else {
            $candidate = $input->allowedDirectoryIds[0] ?? null;
            if ($candidate === null) {
                throw new BadRequestHttpException('This placeholder has no folder to upload into.');
            }
        }

        if (!Uuid::isValid($candidate)) {
            throw new BadRequestHttpException('Invalid directory id.');
        }

        try {
            $directory = $this->fileDirectoryRepository->get(Uuid::fromString($candidate));
        } catch (FileDirectoryNotFound) {
            throw new BadRequestHttpException('Directory not found.');
        }

        if (!$directory->project->id->equals($project->id)) {
            throw new AccessDeniedHttpException();
        }

        return $directory->id;
    }
}
