<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use WBoost\Web\Value\TemplateDimension;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\DimensionPreset;

final class TemplateGroupDimensionFormData
{
    private const int MAX_CANVAS_PIXELS = 10000;
    private const int MIN_CANVAS_PIXELS = 16;

    /**
     * Set by the preset buttons (Instagram formats). When present it WINS
     * over unit/width/height — the JS clears it again on any manual edit, and
     * trusting the marker server-side keeps the outcome deterministic even if
     * the hidden field and the visible inputs somehow disagree.
     */
    public null|DimensionPreset $preset = null;

    public null|DimensionUnit $unit = DimensionUnit::Mm;

    // A4 portrait by default.
    public null|float $width = 210;

    public null|float $height = 297;

    #[NotNull(message: 'Nahrajte pozadí varianty.')]
    public null|UploadedFile $backgroundImage = null;

    #[Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        // A preset is a fixed pixel size — nothing free-form to validate.
        if ($this->preset !== null) {
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

        $dimension = $this->dimension();

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

    public function dimension(): TemplateDimension
    {
        if ($this->preset !== null) {
            return TemplateDimension::fromPreset($this->preset);
        }

        assert($this->unit !== null && $this->width !== null && $this->height !== null);

        return new TemplateDimension($this->unit, $this->width, $this->height);
    }
}
