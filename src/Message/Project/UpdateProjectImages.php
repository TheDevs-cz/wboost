<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Project;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\FormData\ProjectImagesFormData;

readonly final class UpdateProjectImages
{
    public function __construct(
        public UuidInterface $projectId,
        public null|UploadedFile $logoHorizontal,
        public null|UploadedFile $logoVertical,
        public null|UploadedFile $logoHorizontalWithClaim,
        public null|UploadedFile $logoVerticalWithClaim,
        public null|UploadedFile $logoSymbol,
    ) {
    }

    public static function fromFormData(UuidInterface $projectId, ProjectImagesFormData $formData): self
    {
        return new self(
            projectId: $projectId,
            logoHorizontal: $formData->logoHorizontal,
            logoVertical: $formData->logoVertical,
            logoHorizontalWithClaim: $formData->logoHorizontalWithClaim,
            logoVerticalWithClaim: $formData->logoVerticalWithClaim,
            logoSymbol: $formData->logoSymbol,
        );
    }
}
