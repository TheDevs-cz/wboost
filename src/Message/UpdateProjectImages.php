<?php

declare(strict_types=1);

namespace BrandManuals\Web\Message;

use BrandManuals\Web\FormData\ProjectImagesFormData;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class UpdateProjectImages
{
    public function __construct(
        public string $projectId,
        public null|UploadedFile $logoHorizontal,
        public null|UploadedFile $logoVertical,
        public null|UploadedFile $logoHorizontalWithClaim,
        public null|UploadedFile $logoVerticalWithClaim,
        public null|UploadedFile $logoSymbol,
    ) {
    }

    public static function fromFormData(string $projectId, ProjectImagesFormData $formData): self
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
