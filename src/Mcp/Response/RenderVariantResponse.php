<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * The metadata half of the `render_variant` reply — the JSON text block that
 * travels ALONGSIDE the picture (see {@see \WBoost\Web\Mcp\Tool\RenderVariantTool}
 * for why an image tool still answers with two content blocks).
 *
 * Two sizes are reported on purpose. `width`/`height` describe the bytes that
 * were actually returned, which for anything larger than a social preset is a
 * downscaled copy; `canvasWidth`/`canvasHeight` are the variant's true design
 * size, i.e. what `export_variant` will produce. An agent measuring something
 * off the preview — "is this headline within the safe area?" — needs the ratio
 * between them, and inferring it from the picture alone is not possible.
 */
readonly final class RenderVariantResponse
{
    /**
     * @param string $format Mime type of the returned image block.
     * @param null|int $width Returned image width in pixels; null when the bytes could not be measured.
     * @param null|int $height Returned image height in pixels; null when the bytes could not be measured.
     * @param list<string> $warnings Things the agent should act on — an overflowing container, ids that addressed nothing.
     */
    public function __construct(
        public string $variantId,
        public string $templateName,
        public string $projectName,
        public string $format,
        public null|int $width,
        public null|int $height,
        public int $canvasWidth,
        public int $canvasHeight,
        public bool $downscaled,
        public array $warnings,
    ) {
    }
}
