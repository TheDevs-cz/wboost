<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

/**
 * `PATCH /api/template-variants/{id}/print-setup` body. Untyped on purpose:
 * the processor validates the RAW values so a wrong type answers the same
 * structured `invalid_bleed` / `invalid_crop_marks` as the document writes,
 * instead of the serializer's generic 400.
 */
final class TemplateVariantPrintSetupRequest
{
    /** Bleed ("spadávka") per side in mm, 0–20. Omit to keep. */
    public mixed $bleedMm = null;

    /** Crop marks in the print PDF (fixed 5 mm margin outside the bleed). Omit to keep. */
    public mixed $cropMarks = null;
}
