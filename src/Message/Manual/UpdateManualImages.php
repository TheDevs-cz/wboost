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
        public null|int $logoHorizontalDisplayWidth,

        public null|UploadedFile $logoVertical,
        public null|string $logoVerticalWidthInfo,
        public null|string $logoVerticalHeightInfo,
        public null|int $logoVerticalDisplayWidth,

        public null|UploadedFile $logoHorizontalWithClaim,
        public null|string $logoHorizontalWithClaimWidthInfo,
        public null|string $logoHorizontalWithClaimHeightInfo,
        public null|int $logoHorizontalWithClaimDisplayWidth,

        public null|UploadedFile $logoVerticalWithClaim,
        public null|string $logoVerticalWithClaimWidthInfo,
        public null|string $logoVerticalWithClaimHeightInfo,
        public null|int $logoVerticalWithClaimDisplayWidth,

        public null|UploadedFile $logoSymbol,
        public null|string $logoSymbolWidthInfo,
        public null|string $logoSymbolHeightInfo,
        public null|int $logoSymbolDisplayWidth,
    ) {
    }

    public static function fromFormData(UuidInterface $manualId, ManualImagesFormData $formData): self
    {
        return new self(
            manualId: $manualId,
            logoHorizontal: $formData->logoHorizontal,
            logoHorizontalWidthInfo: $formData->logoHorizontalWidthInfo,
            logoHorizontalHeightInfo: $formData->logoHorizontalHeightInfo,
            logoHorizontalDisplayWidth: $formData->logoHorizontalDisplayWidth,
            logoVertical: $formData->logoVertical,
            logoVerticalWidthInfo: $formData->logoVerticalWidthInfo,
            logoVerticalHeightInfo: $formData->logoVerticalHeightInfo,
            logoVerticalDisplayWidth: $formData->logoVerticalDisplayWidth,
            logoHorizontalWithClaim: $formData->logoHorizontalWithClaim,
            logoHorizontalWithClaimWidthInfo: $formData->logoHorizontalWithClaimWidthInfo,
            logoHorizontalWithClaimHeightInfo: $formData->logoHorizontalWithClaimHeightInfo,
            logoHorizontalWithClaimDisplayWidth: $formData->logoHorizontalWithClaimDisplayWidth,
            logoVerticalWithClaim: $formData->logoVerticalWithClaim,
            logoVerticalWithClaimWidthInfo: $formData->logoVerticalWithClaimWidthInfo,
            logoVerticalWithClaimHeightInfo: $formData->logoVerticalWithClaimHeightInfo,
            logoVerticalWithClaimDisplayWidth: $formData->logoVerticalWithClaimDisplayWidth,
            logoSymbol: $formData->logoSymbol,
            logoSymbolWidthInfo: $formData->logoSymbolWidthInfo,
            logoSymbolHeightInfo: $formData->logoSymbolHeightInfo,
            logoSymbolDisplayWidth: $formData->logoSymbolDisplayWidth,
        );
    }
}
