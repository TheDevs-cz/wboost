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
    public bool $logoHorizontalOwnPage = false;

    public null|UploadedFile $logoVertical = null;
    public null|string $logoVerticalWidthInfo = null;
    public null|string $logoVerticalHeightInfo = null;
    public null|int $logoVerticalDisplayWidth = null;
    public bool $logoVerticalOwnPage = false;

    public null|UploadedFile $logoHorizontalWithClaim = null;
    public null|string $logoHorizontalWithClaimWidthInfo = null;
    public null|string $logoHorizontalWithClaimHeightInfo = null;
    public null|int $logoHorizontalWithClaimDisplayWidth = null;
    public bool $logoHorizontalWithClaimOwnPage = false;

    public null|UploadedFile $logoVerticalWithClaim = null;
    public null|string $logoVerticalWithClaimWidthInfo = null;
    public null|string $logoVerticalWithClaimHeightInfo = null;
    public null|int $logoVerticalWithClaimDisplayWidth = null;
    public bool $logoVerticalWithClaimOwnPage = false;

    public null|UploadedFile $logoSymbol = null;
    public null|string $logoSymbolWidthInfo = null;
    public null|string $logoSymbolHeightInfo = null;
    public null|int $logoSymbolDisplayWidth = null;
    public bool $logoSymbolOwnPage = false;

    public static function fromManual(Manual $manual): self
    {
        $self = new self();

        $self->logoHorizontalWidthInfo = $manual->logo->horizontal?->widthInfo;
        $self->logoHorizontalHeightInfo = $manual->logo->horizontal?->heightInfo;
        $self->logoHorizontalDisplayWidth = $manual->logo->horizontal?->displayWidth;
        $self->logoHorizontalOwnPage = $manual->logo->horizontal?->ownPage === true;
        $self->logoVerticalWidthInfo = $manual->logo->vertical?->widthInfo;
        $self->logoVerticalHeightInfo = $manual->logo->vertical?->heightInfo;
        $self->logoVerticalDisplayWidth = $manual->logo->vertical?->displayWidth;
        $self->logoVerticalOwnPage = $manual->logo->vertical?->ownPage === true;
        $self->logoHorizontalWithClaimWidthInfo = $manual->logo->horizontalWithClaim?->widthInfo;
        $self->logoHorizontalWithClaimHeightInfo = $manual->logo->horizontalWithClaim?->heightInfo;
        $self->logoHorizontalWithClaimDisplayWidth = $manual->logo->horizontalWithClaim?->displayWidth;
        $self->logoHorizontalWithClaimOwnPage = $manual->logo->horizontalWithClaim?->ownPage === true;
        $self->logoVerticalWithClaimWidthInfo = $manual->logo->verticalWithClaim?->widthInfo;
        $self->logoVerticalWithClaimHeightInfo = $manual->logo->verticalWithClaim?->heightInfo;
        $self->logoVerticalWithClaimDisplayWidth = $manual->logo->verticalWithClaim?->displayWidth;
        $self->logoVerticalWithClaimOwnPage = $manual->logo->verticalWithClaim?->ownPage === true;
        $self->logoSymbolWidthInfo = $manual->logo->symbol?->widthInfo;
        $self->logoSymbolHeightInfo = $manual->logo->symbol?->heightInfo;
        $self->logoSymbolDisplayWidth = $manual->logo->symbol?->displayWidth;
        $self->logoSymbolOwnPage = $manual->logo->symbol?->ownPage === true;

        return $self;
    }
}
