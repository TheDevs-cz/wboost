<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\Form\FormInterface;
use WBoost\Web\Value\LogoColorVariant;
use WBoost\Web\Value\LogoTypeVariant;

/**
 * The "Mapování barev loga" form of one logo type × colour variant, plus what
 * its modal body prints beside the fields: the colours the SVG contains (the
 * FROM column, positionally aligned with `form.colors`) and the default card
 * background.
 */
final readonly class LogoColorsEditForm
{
    /**
     * @param FormInterface<LogoColorsFormData> $form
     * @param list<string> $detectedColors
     */
    public function __construct(
        public FormInterface $form,
        public LogoTypeVariant $logoVariant,
        public LogoColorVariant $colorVariant,
        public array $detectedColors,
        public string $defaultBackground,
    ) {
    }

    /** The id of the manual-preview card this mapping recolours (`manual_preview.html.twig`). */
    public static function cardId(LogoTypeVariant $logoVariant, LogoColorVariant $colorVariant): string
    {
        return sprintf('logo-card-%s-%s', $logoVariant->value, $colorVariant->value);
    }

    /** The id of the modal body the Turbo Stream re-renders (`_manual_logo_colors_form_body.html.twig`). */
    public static function bodyId(LogoTypeVariant $logoVariant, LogoColorVariant $colorVariant): string
    {
        return sprintf('logo-colors-form-%s-%s', $logoVariant->value, $colorVariant->value);
    }
}
