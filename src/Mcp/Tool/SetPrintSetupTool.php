<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use WBoost\Web\Exceptions\InvalidPrintSetup;
use WBoost\Web\Mcp\Design\DesignVariants;
use WBoost\Web\Mcp\Response\SetPrintSetupResponse;
use WBoost\Web\Mcp\Response\VariantDimensionResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Template\VariantPrintSetup;

/**
 * `set_print_setup` — bleed + crop marks of a standalone print variant, the
 * MCP twin of `PATCH /api/template-variants/{id}/print-setup` (same
 * {@see VariantPrintSetup} service, same codes). A separate tool rather than
 * a `set_design` flag: the print setup is not part of the design document
 * and changing it must not require re-sending one; `manage_document`
 * "set_print" stays the document's counterpart.
 *
 * Access is {@see DesignVariants::editable()} (VIEW + EDIT) — NOT
 * `writable()`: a grouped variant is accepted, like on the web, because its
 * print setup is its own.
 */
#[McpToolScope(McpScope::TemplatesDesign)]
readonly final class SetPrintSetupTool
{
    public function __construct(
        private DesignVariants $variants,
        private VariantPrintSetup $printSetup,
        private TemplateVariantRepository $variantRepository,
    ) {
    }

    /**
     * Sets the PRINT SETUP of one print-format template variant (unit mm / cm —
     * describe_variant dimension.printFormat true): its bleed and crop marks,
     * used by the print PDF (export_variant format "pdf").
     *
     * bleedMm ("spadávka", 0–20 mm per side) is how far the artwork runs PAST
     * the cut — the printer trims it off, so a picture or colour reaching the
     * paper edge never ends in a white hairline. cropMarks adds the cut marks,
     * drawn in a fixed 5 mm margin outside the bleed (PDF page = trim + 2 ×
     * bleed + 2 × 5 mm). Changing the bleed re-fits the background layer to the
     * new bleed box; other artwork meant to reach the edge must extend
     * dimension.bleedPx past every canvas edge (set_design).
     *
     * When the user has NOT said which bleed they want, ASK the user before
     * calling this — suggest the common print default, 3 mm with crop marks
     * (bleedMm: 3, cropMarks: true). A print variant reporting bleedMm 0 has
     * no bleed at all; mention it before a print export when artwork reaches
     * the edge.
     *
     * An omitted value keeps its current setting. Refusals name their code:
     * invalid_bleed (outside 0–20, never clamped), invalid_crop_marks,
     * missing_print_setup (neither given), print_setup_not_available (a px /
     * social format — those have no bleed), document_page (the variant is a
     * page of a multi-page document, whose pages all follow the document: use
     * manage_document "set_print" with the documentId the message names). A
     * variant of a synchronized group is accepted and changes only this
     * dimension, as on the web.
     *
     * @param string $variantId UUID of the print variant (find_templates / describe_variant).
     * @param null|float $bleedMm Bleed per side in mm, 0–20; 3 is the common print default. Omit to keep.
     * @param null|bool $cropMarks Crop marks in the print PDF. Omit to keep.
     */
    #[McpTool(name: 'set_print_setup')]
    public function __invoke(
        string $variantId,
        null|float $bleedMm = null,
        null|bool $cropMarks = null,
    ): SetPrintSetupResponse {
        $variant = $this->variants->editable($variantId);

        try {
            $this->printSetup->change($variant, array_filter(
                ['bleedMm' => $bleedMm, 'cropMarks' => $cropMarks],
                static fn (null|float|bool $value): bool => $value !== null,
            ));
        } catch (InvalidPrintSetup $refused) {
            throw new ToolCallException(sprintf(
                '%s (%s%s)',
                $refused->getMessage(),
                $refused->reason,
                $refused->documentId !== null ? sprintf(', documentId %s — manage_document {"action": "set_print", "documentId": "%s", …}', $refused->documentId, $refused->documentId) : '',
            ));
        }

        $variant = $this->variantRepository->get($variant->id);
        $dimension = $variant->dimension;

        return new SetPrintSetupResponse(
            variantId: $variant->id->toString(),
            templateName: $variant->template->name,
            projectName: $variant->template->project->name,
            grouped: $variant->group !== null,
            dimension: new VariantDimensionResponse(
                label: $dimension->label(),
                preset: $dimension->preset?->value,
                unit: $dimension->unit->value,
                unitWidth: $dimension->unitWidth,
                unitHeight: $dimension->unitHeight,
                width: $dimension->width(),
                height: $dimension->height(),
                printFormat: $dimension->isPrintFormat(),
                bleedMm: $dimension->bleedMm,
                bleedPx: $dimension->bleedPixels(),
                cropMarks: $dimension->cropMarks,
            ),
            status: sprintf(
                'Saved: bleed %s mm per side, %s. The print PDF (export_variant format "pdf") uses it from now on.',
                rtrim(rtrim(number_format($dimension->bleedMm, 2, '.', ''), '0'), '.'),
                $dimension->cropMarks ? 'with crop marks' : 'no crop marks',
            ),
        );
    }
}
