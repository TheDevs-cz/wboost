<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use WBoost\Web\Value\DimensionPreset;
use WBoost\Web\Value\GroupVariantSelection;
use WBoost\Web\Value\TemplateDimension;

final class TemplateGroupFormData
{
    #[NotBlank]
    public null|string $name = null;

    public null|string $category = null;

    /** @var list<DimensionPreset> */
    public array $presetDimensions = [];

    // One optional upload per enum case. Field names use the case NAMES
    // because enum values like "1:1" are not valid form field names.
    public null|UploadedFile $backgroundInstagramPost = null;

    public null|UploadedFile $backgroundInstagramPortrait = null;

    public null|UploadedFile $backgroundInstagramStory = null;

    /**
     * Convenience fallback: fills every selected dimension that has no
     * upload of its own.
     */
    public null|UploadedFile $commonBackground = null;

    /** @var list<TemplateVariantFormData> */
    public array $customDimensions = [];

    // "Create from existing template" design source (hidden field, set by the
    // picker page). When present, dimensions without an upload reuse a copy of
    // the source variant's background.
    public null|string $sourceVariantId = null;

    #[Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->presetDimensions === [] && $this->customDimensions === []) {
            $context->buildViolation('Vyberte alespoň jeden rozměr.')
                ->atPath('presetDimensions')
                ->addViolation();
        }

        // Backgrounds are optional since the background-as-layer rework: a
        // dimension without an upload (and without a design source to copy
        // from) starts without a background and renders transparent.
    }

    public function hasDesignSource(): bool
    {
        return $this->sourceVariantId !== null && $this->sourceVariantId !== '';
    }

    public function backgroundFor(DimensionPreset $dimension): null|UploadedFile
    {
        $own = match ($dimension) {
            DimensionPreset::InstagramPost => $this->backgroundInstagramPost,
            DimensionPreset::InstagramPortrait => $this->backgroundInstagramPortrait,
            DimensionPreset::InstagramStory => $this->backgroundInstagramStory,
        };

        return $own ?? $this->commonBackground;
    }

    /**
     * @return list<GroupVariantSelection>
     */
    public function variantSelections(): array
    {
        $selections = [];

        foreach ($this->presetDimensions as $preset) {
            $selections[] = new GroupVariantSelection(TemplateDimension::fromPreset($preset), $this->backgroundFor($preset));
        }

        foreach ($this->customDimensions as $row) {
            $selections[] = new GroupVariantSelection($row->dimension(), $row->backgroundImage ?? $this->commonBackground);
        }

        return $selections;
    }
}
