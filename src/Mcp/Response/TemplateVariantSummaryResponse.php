<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * One variant, summarised — enough to choose between variants, not enough to
 * work on one. The full picture (inputs, image slots, containers, the design
 * itself) is what `describe_variant` is for.
 *
 * `grouped` is the load-bearing flag. A variant created by a template group is
 * authored ONLY through the group editor: a single-variant write would be
 * clobbered by the next group save, so the design tools refuse it. The flag
 * lives on the VARIANT rather than only on the template because the two can
 * disagree — a grouped template also accepts hand-added variants, and those
 * carry no group and stay individually editable.
 *
 * `inputCount` counts the variant's text inputs exactly as
 * `GET /api/projects/{projectId}/templates` lists them in `inputs[]` — locked
 * ones included (they are part of the design an agent reads), design-hidden
 * ones excluded (an invisible layer is not fillable, so it never becomes an
 * input in the first place).
 *
 * `thumbnailUrl` is the rendered preview when the variant has one, falling back
 * to its background image — the same "preview, else background" the web
 * listing shows. It is null when the variant has neither; there is no
 * placeholder image, because a URL that resolves to a grey square would read as
 * a real design.
 */
readonly final class TemplateVariantSummaryResponse
{
    public function __construct(
        public string $id,
        public VariantDimensionResponse $dimension,
        public null|string $thumbnailUrl,
        public int $inputCount,
        public bool $grouped,
    ) {
    }
}
