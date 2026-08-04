<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\Validator\Constraints\Valid;

/**
 * The one-step "Nová šablona" form: template metadata + the FIRST variant's
 * dimension in a single submit. Composes the two existing form-data objects
 * so their validation (name length, canvas px bounds, preset handling) stays
 * single-sourced; editing a template and adding FURTHER variants keep using
 * the standalone forms.
 */
final class AddTemplateFormData
{
    #[Valid]
    public TemplateFormData $template;

    #[Valid]
    public TemplateVariantFormData $variant;

    public function __construct()
    {
        $this->template = new TemplateFormData();
        $this->variant = new TemplateVariantFormData();
    }
}
