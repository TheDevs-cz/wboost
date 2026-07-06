<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use WBoost\Web\Value\TemplateDimension;

final class TemplateGroupFormData
{
    #[NotBlank]
    public null|string $name = null;

    public null|string $socialCategory = null;

    public null|string $customCategory = null;

    /** @var list<TemplateDimension> */
    public array $socialDimensions = [];

    // One optional upload per enum case. Field names use the case NAMES
    // because enum values like "1:1" are not valid form field names.
    public null|UploadedFile $backgroundInstagramPost = null;

    public null|UploadedFile $backgroundInstagramPortrait = null;

    public null|UploadedFile $backgroundInstagramStory = null;

    /**
     * Convenience fallback: fills every selected dimension (both modules)
     * that has no upload of its own.
     */
    public null|UploadedFile $commonBackground = null;

    /** @var list<CustomTemplateVariantFormData> */
    public array $customDimensions = [];

    #[Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->socialDimensions === [] && $this->customDimensions === []) {
            $context->buildViolation('Vyberte alespoň jeden rozměr.')
                ->atPath('socialDimensions')
                ->addViolation();
        }

        foreach ($this->socialDimensions as $dimension) {
            if ($this->backgroundFor($dimension) === null) {
                $context->buildViolation('Nahrajte pozadí pro tento rozměr, nebo použijte společné pozadí.')
                    ->atPath('background' . $dimension->name)
                    ->addViolation();
            }
        }

        foreach ($this->customDimensions as $index => $row) {
            if ($row->backgroundImage === null && $this->commonBackground === null) {
                $context->buildViolation('Nahrajte pozadí pro tento rozměr, nebo použijte společné pozadí.')
                    ->atPath(sprintf('customDimensions[%d].backgroundImage', $index))
                    ->addViolation();
            }
        }
    }

    public function backgroundFor(TemplateDimension $dimension): null|UploadedFile
    {
        $own = match ($dimension) {
            TemplateDimension::InstagramPost => $this->backgroundInstagramPost,
            TemplateDimension::InstagramPortrait => $this->backgroundInstagramPortrait,
            TemplateDimension::InstagramStory => $this->backgroundInstagramStory,
        };

        return $own ?? $this->commonBackground;
    }
}
