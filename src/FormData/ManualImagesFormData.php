<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Entity\Manual;

final class ManualImagesFormData
{
    public null|UploadedFile $logoHorizontal = null;
    public null|string $logoHorizontalWidthInfo = null;
    public null|string $logoHorizontalHeightInfo = null;
    public null|int $logoHorizontalDisplayWidth = null;

    public null|UploadedFile $logoVertical = null;
    public null|string $logoVerticalWidthInfo = null;
    public null|string $logoVerticalHeightInfo = null;
    public null|int $logoVerticalDisplayWidth = null;

    public null|UploadedFile $logoHorizontalWithClaim = null;
    public null|string $logoHorizontalWithClaimWidthInfo = null;
    public null|string $logoHorizontalWithClaimHeightInfo = null;
    public null|int $logoHorizontalWithClaimDisplayWidth = null;

    public null|UploadedFile $logoVerticalWithClaim = null;
    public null|string $logoVerticalWithClaimWidthInfo = null;
    public null|string $logoVerticalWithClaimHeightInfo = null;
    public null|int $logoVerticalWithClaimDisplayWidth = null;

    public null|UploadedFile $logoSymbol = null;
    public null|string $logoSymbolWidthInfo = null;
    public null|string $logoSymbolHeightInfo = null;
    public null|int $logoSymbolDisplayWidth = null;

    public static function fromManual(Manual $manual): self
    {
        $self = new self();

        $self->logoHorizontalWidthInfo = $manual->logo->horizontal?->widthInfo;
        $self->logoHorizontalHeightInfo = $manual->logo->horizontal?->heightInfo;
        $self->logoHorizontalDisplayWidth = $manual->logo->horizontal?->displayWidth;
        $self->logoVerticalWidthInfo = $manual->logo->vertical?->widthInfo;
        $self->logoVerticalHeightInfo = $manual->logo->vertical?->heightInfo;
        $self->logoVerticalDisplayWidth = $manual->logo->vertical?->displayWidth;
        $self->logoHorizontalWithClaimWidthInfo = $manual->logo->horizontalWithClaim?->widthInfo;
        $self->logoHorizontalWithClaimHeightInfo = $manual->logo->horizontalWithClaim?->heightInfo;
        $self->logoHorizontalWithClaimDisplayWidth = $manual->logo->horizontalWithClaim?->displayWidth;
        $self->logoVerticalWithClaimWidthInfo = $manual->logo->verticalWithClaim?->widthInfo;
        $self->logoVerticalWithClaimHeightInfo = $manual->logo->verticalWithClaim?->heightInfo;
        $self->logoVerticalWithClaimDisplayWidth = $manual->logo->verticalWithClaim?->displayWidth;
        $self->logoSymbolWidthInfo = $manual->logo->symbol?->widthInfo;
        $self->logoSymbolHeightInfo = $manual->logo->symbol?->heightInfo;
        $self->logoSymbolDisplayWidth = $manual->logo->symbol?->displayWidth;

        return $self;
    }
}
