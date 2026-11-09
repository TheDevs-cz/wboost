<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;

/**
 * The print setup of a standalone print variant after
 * `PATCH /api/template-variants/{id}/print-setup` — the same numbers the
 * templates listing reports on `variants[]` (`bleedMm`, `cropMarks`) plus the
 * bleed in canvas px (300 DPI) that artwork must reach past every edge.
 */
#[ApiResource(
    shortName: 'TemplateVariantPrintSetup',
    operations: [
        new Patch(
            uriTemplate: '/template-variants/{variantId}/print-setup',
            uriVariables: [
                'variantId' => new Link(fromClass: TemplateVariantPrintSetupResponse::class, identifiers: [], parameterName: 'variantId'),
            ],
            inputFormats: ['json' => ['application/json', 'application/merge-patch+json']],
            input: TemplateVariantPrintSetupRequest::class,
            read: false,
            processor: TemplateVariantPrintSetupProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            normalizationContext: ['skip_null_values' => false],
            name: 'api_template_variant_print_setup',
            openapi: new OpenApiOperation(
                summary: 'Set the bleed / crop marks of a standalone print (mm / cm) variant: `{bleedMm?: 0–20, cropMarks?: bool}` (absent key = unchanged). 400 `{error, code}`: invalid_bleed, invalid_crop_marks, missing_print_setup, print_setup_not_available (px), document_page (+ documentId — change the document instead).',
            ),
        ),
    ],
)]
final readonly class TemplateVariantPrintSetupResponse
{
    public function __construct(
        public string $id,
        public string $unit,
        public bool $printFormat,
        public float $bleedMm,
        public int $bleedPx,
        public bool $cropMarks,
    ) {
    }
}
