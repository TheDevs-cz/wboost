<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use WBoost\Web\Value\CustomTemplateDimension;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\TemplateDimension;

final class TemplateGroupDimensionFormData
{
    public const string MODULE_SOCIAL = 'social';
    public const string MODULE_CUSTOM = 'custom';

    private const int MAX_CANVAS_PIXELS = 10000;
    private const int MIN_CANVAS_PIXELS = 16;

    #[NotBlank]
    public null|string $module = self::MODULE_SOCIAL;

    public null|TemplateDimension $socialDimension = TemplateDimension::InstagramPost;

    public null|DimensionUnit $unit = DimensionUnit::Mm;

    // A4 portrait by default.
    public null|float $width = 210;

    public null|float $height = 297;

    #[NotNull(message: 'Nahrajte pozadí varianty.')]
    public null|UploadedFile $backgroundImage = null;

    #[Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->module === self::MODULE_SOCIAL) {
            if ($this->socialDimension === null) {
                $context->buildViolation('Vyberte rozměr.')
                    ->atPath('socialDimension')
                    ->addViolation();
            }

            return;
        }

        if ($this->unit === null || $this->width === null || $this->height === null
            || $this->width <= 0 || $this->height <= 0
        ) {
            $context->buildViolation('Zadejte platné rozměry.')
                ->atPath('width')
                ->addViolation();

            return;
        }

        $dimension = $this->customDimension();

        foreach (['width' => $dimension->width(), 'height' => $dimension->height()] as $field => $pixels) {
            if ($pixels > self::MAX_CANVAS_PIXELS) {
                $context->buildViolation(sprintf('Rozměr je příliš velký — maximum je %d px (%d px požadováno).', self::MAX_CANVAS_PIXELS, $pixels))
                    ->atPath($field)
                    ->addViolation();
            }

            if ($pixels < self::MIN_CANVAS_PIXELS) {
                $context->buildViolation(sprintf('Rozměr je příliš malý — minimum je %d px.', self::MIN_CANVAS_PIXELS))
                    ->atPath($field)
                    ->addViolation();
            }
        }
    }

    public function customDimension(): CustomTemplateDimension
    {
        assert($this->unit !== null && $this->width !== null && $this->height !== null);

        return new CustomTemplateDimension($this->unit, $this->width, $this->height);
    }
}
