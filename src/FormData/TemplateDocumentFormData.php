<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Valid;

/**
 * "Nový dokument": name, category and the ONE page size every page renders in
 * (+ its print setup) — the dimension block is the add-variant form's, so its
 * validation (px bounds, presets, bleed range) stays single-sourced.
 */
final class TemplateDocumentFormData
{
    #[NotBlank(normalizer: 'trim')]
    #[Length(min: 3, max: 60)]
    public string $name = '';

    public null|string $category = null;

    #[Valid]
    public TemplateVariantFormData $dimension;

    public function __construct()
    {
        $this->dimension = new TemplateVariantFormData();
    }
}
