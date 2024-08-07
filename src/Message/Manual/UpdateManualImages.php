<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\FormData\ManualImagesFormData;

readonly final class UpdateManualImages
{
    public function __construct(
        public UuidInterface $manualId,
        public null|UploadedFile $logoHorizontal,
        public null|UploadedFile $logoVertical,
        public null|UploadedFile $logoHorizontalWithClaim,
        public null|UploadedFile $logoVerticalWithClaim,
        public null|UploadedFile $logoSymbol,
    ) {
    }

    public static function fromFormData(UuidInterface $manualId, ManualImagesFormData $formData): self
    {
        return new self(
            manualId: $manualId,
            logoHorizontal: $formData->logoHorizontal,
            logoVertical: $formData->logoVertical,
            logoHorizontalWithClaim: $formData->logoHorizontalWithClaim,
            logoVerticalWithClaim: $formData->logoVerticalWithClaim,
            logoSymbol: $formData->logoSymbol,
        );
    }
}
