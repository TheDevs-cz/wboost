<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * The metadata half of the `export_variant` reply — the JSON text block that
 * travels ALONGSIDE the delivered PNG (see
 * {@see \WBoost\Web\Mcp\Tool\ExportVariantTool} for why an image tool still
 * answers with two content blocks).
 *
 * Unlike {@see RenderVariantResponse} there is only ONE size here, and that is
 * the point: an export is the variant at its designed dimensions, never scaled,
 * never re-encoded. `width`/`height` are read off {@see
 * \WBoost\Web\Value\TemplateDimension} rather than measured, because they are
 * what the renderer was ASKED for — a picture that came back at another size
 * would be a renderer defect, and reporting the measurement would hide it.
 *
 * `sizeBytes` is the raw PNG length before base64. An agent that has to decide
 * whether to ask for a second export in the same conversation needs it: the
 * encoded block is ~4/3 of this number and stays in the context window.
 */
readonly final class ExportVariantResponse
{
    /**
     * @param string $format Mime type of the returned image block — always `image/png`.
     * @param int $width Exported image width in pixels; the variant's designed width.
     * @param int $height Exported image height in pixels; the variant's designed height.
     * @param int $sizeBytes Size of the PNG before base64 encoding.
     * @param list<string> $warnings Things the agent should act on — ids that addressed nothing, locked inputs.
     */
    public function __construct(
        public string $variantId,
        public string $templateName,
        public string $projectName,
        public string $format,
        public int $width,
        public int $height,
        public int $sizeBytes,
        public array $warnings,
    ) {
    }
}
