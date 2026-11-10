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
 * `set_print_setup` — crop marks of a standalone print variant, the
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
     * Sets the CROP MARKS of one print-format template variant (unit mm / cm —
     * describe_variant dimension.printFormat true), used by the print PDF
     * (export_variant format "pdf").
     *
     * cropMarks adds the cut marks, drawn in a fixed 5 mm margin outside the
     * bleed (PDF page = trim + 2 × 3 mm + 2 × 5 mm). The bleed itself is fixed
     * at 3 mm per side and cannot be changed: artwork meant to reach the paper
     * edge must extend dimension.bleedPx past every canvas edge (set_design).
     *
     * An omitted value keeps the current setting. Refusals name their code:
     * invalid_crop_marks, print_setup_not_available (a px / social format —
     * those have no print PDF), document_page (the variant is a
     * page of a multi-page document, whose pages all follow the document: use
     * manage_document "set_print" with the documentId the message names). A
     * variant of a synchronized group is accepted and changes only this
     * dimension, as on the web.
     *
     * @param string $variantId UUID of the print variant (find_templates / describe_variant).
     * @param null|bool $cropMarks Crop marks in the print PDF. Omit to keep.
     */
    #[McpTool(name: 'set_print_setup')]
    public function __invoke(
        string $variantId,
        null|bool $cropMarks = null,
    ): SetPrintSetupResponse {
        $variant = $this->variants->editable($variantId);

        try {
            $this->printSetup->change($variant, $cropMarks !== null ? ['cropMarks' => $cropMarks] : []);
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
                'Print setup: fixed %s mm bleed per side, %s. The print PDF (export_variant format "pdf") uses it from now on.',
                rtrim(rtrim(number_format($dimension->bleedMm, 2, '.', ''), '0'), '.'),
                $dimension->cropMarks ? 'with crop marks' : 'no crop marks',
            ),
        );
    }
}
