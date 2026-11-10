<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

/**
 * `PATCH /api/template-variants/{id}/print-setup` body. Untyped on purpose:
 * the processor validates the RAW value so a wrong type answers the same
 * structured `invalid_crop_marks` as the document writes, instead of the
 * serializer's generic 400. There is no `bleedMm`: the bleed is fixed at 3 mm
 * and a client still sending it is ignored.
 */
final class TemplateVariantPrintSetupRequest
{
    /** Crop marks in the print PDF (fixed 5 mm margin outside the bleed). Omit to keep. */
    public mixed $cropMarks = null;
}
