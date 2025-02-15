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
        public null|string $logoHorizontalWidthInfo,
        public null|string $logoHorizontalHeightInfo,

        public null|UploadedFile $logoVertical,
        public null|string $logoVerticalWidthInfo,
        public null|string $logoVerticalHeightInfo,

        public null|UploadedFile $logoHorizontalWithClaim,
        public null|string $logoHorizontalWithClaimWidthInfo,
        public null|string $logoHorizontalWithClaimHeightInfo,

        public null|UploadedFile $logoVerticalWithClaim,
        public null|string $logoVerticalWithClaimWidthInfo,
        public null|string $logoVerticalWithClaimHeightInfo,

        public null|UploadedFile $logoSymbol,
        public null|string $logoSymbolWidthInfo,
        public null|string $logoSymbolHeightInfo,
    ) {
    }

    public static function fromFormData(UuidInterface $manualId, ManualImagesFormData $formData): self
    {
        return new self(
            manualId: $manualId,
            logoHorizontal: $formData->logoHorizontal,
            logoHorizontalWidthInfo: $formData->logoHorizontalWidthInfo,
            logoHorizontalHeightInfo: $formData->logoHorizontalHeightInfo,
            logoVertical: $formData->logoVertical,
            logoVerticalWidthInfo: $formData->logoVerticalWidthInfo,
            logoVerticalHeightInfo: $formData->logoVerticalHeightInfo,
            logoHorizontalWithClaim: $formData->logoHorizontalWithClaim,
            logoHorizontalWithClaimWidthInfo: $formData->logoHorizontalWithClaimWidthInfo,
            logoHorizontalWithClaimHeightInfo: $formData->logoHorizontalWithClaimHeightInfo,
            logoVerticalWithClaim: $formData->logoVerticalWithClaim,
            logoVerticalWithClaimWidthInfo: $formData->logoVerticalWithClaimWidthInfo,
            logoVerticalWithClaimHeightInfo: $formData->logoVerticalWithClaimHeightInfo,
            logoSymbol: $formData->logoSymbol,
            logoSymbolWidthInfo: $formData->logoSymbolWidthInfo,
            logoSymbolHeightInfo: $formData->logoSymbolHeightInfo,
        );
    }
}
