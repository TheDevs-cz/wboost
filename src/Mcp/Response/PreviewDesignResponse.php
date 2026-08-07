<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * The JSON half of every `preview_design` reply — BOTH outcomes.
 *
 * One shape for "here is your picture, with three concerns" and for "nothing
 * was drawn, fix these two errors" is deliberate: the loop this tool serves is
 * an agent calling it over and over, and a reply whose structure changes with
 * the outcome makes the model branch before it can read anything. So the fields
 * are always the same and three of them carry the whole verdict:
 *
 * - {@see $rendered} — is there a picture in this reply at all?
 * - {@see $errorCount} / {@see $warningCount} — how much is blocking, how much
 *   is advice;
 * - {@see $status} — the same thing as one English sentence, because it is what
 *   a model reads first and what it will paraphrase to its user.
 *
 * When {@see $rendered} is false the reply carries no image block and the four
 * picture fields are null; the variant's own identity and size are still
 * reported, because "which variant did I just fail against, and how big is it"
 * is exactly what the agent needs to write the corrected document.
 */
readonly final class PreviewDesignResponse
{
    /**
     * @param bool $rendered Whether an image block follows this summary.
     * @param string $status One-sentence verdict, safe to show a human as-is.
     * @param int $errorCount Issues that BLOCKED the render; zero when rendered.
     * @param int $warningCount Advisory issues; never block.
     * @param list<array<string, mixed>> $issues Every finding, in pipeline order — each with severity, stage, code, path and an actionable message.
     * @param null|string $format Mime type of the image block, null when nothing was drawn.
     * @param null|int $width Returned image width in pixels; null when nothing was drawn or the bytes could not be measured.
     * @param null|int $height Returned image height in pixels; null when nothing was drawn or the bytes could not be measured.
     * @param null|bool $downscaled Whether the picture is smaller than the canvas; null when nothing was drawn.
     * @param int $canvasWidth The variant's real width in canvas pixels — what a committed design renders at.
     * @param int $canvasHeight The variant's real height in canvas pixels.
     */
    public function __construct(
        public string $variantId,
        public string $templateName,
        public string $projectName,
        public bool $rendered,
        public string $status,
        public int $errorCount,
        public int $warningCount,
        public array $issues,
        public null|string $format,
        public null|int $width,
        public null|int $height,
        public null|bool $downscaled,
        public int $canvasWidth,
        public int $canvasHeight,
    ) {
    }
}
