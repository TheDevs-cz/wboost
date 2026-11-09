<?php

declare(strict_types=1);

namespace WBoost\Web\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use WBoost\Web\Entity\Manual;
use WBoost\Web\FormData\LogoColorsEditForm;
use WBoost\Web\Services\Manual\ManualEditForms;
use WBoost\Web\Value\LogoColorVariant;
use WBoost\Web\Value\LogoTypeVariant;

/**
 * The "Mapování barev loga" modal of one logo type × colour variant. Opened by
 * the pencil on the logo card (`components/LogoColorsMapping.html.twig`) but
 * rendered OUTSIDE the card — once, at the end of the manual preview — so the
 * Turbo Stream answering a save (`EditManualLogoColorsMappingController`) can
 * replace the whole card without touching the open modal. A plain (not Live)
 * component since 2026-11-09; the Live action used to redirect, and every save
 * threw the admin back to the top of the manual.
 */
#[AsTwigComponent('LogoColorsMappingModal')]
final class LogoColorsMappingModalComponent
{
    public null|Manual $manual = null;

    public string $logoVariant = '';

    public string $colorVariant = '';

    public function __construct(
        readonly private ManualEditForms $forms,
    ) {
    }

    /** Null when the manual has no image for this logo variant — nothing to map, no modal. */
    public function edit(): null|LogoColorsEditForm
    {
        assert($this->manual !== null);

        $logoVariant = LogoTypeVariant::from($this->logoVariant);

        if ($this->manual->logo->variant($logoVariant) === null) {
            return null;
        }

        return $this->forms->logoColors($this->manual, $logoVariant, LogoColorVariant::from($this->colorVariant));
    }
}
