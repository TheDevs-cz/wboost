<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * The JSON half of every `set_design` reply — both outcomes, exactly as
 * {@see PreviewDesignResponse} does it, and for the same reason: the two tools
 * are one loop, and a model that has learned to read a preview must not have to
 * learn a second reader for the commit.
 *
 * The fields line up one for one with `preview_design`, with `rendered`
 * replaced by {@see $saved} — because that is genuinely the different question.
 * `preview_design` asks *"is there a picture?"*; here there is a picture only
 * when something was written, and "was it written" is what the agent has to
 * know before it decides whether to try again.
 *
 * Two fields are this tool's own:
 *
 * - {@see $editorUrl} — where a human opens what the agent just made. It is
 *   returned on a refusal too: the design was not written, but the variant is
 *   still the thing under discussion, and a link a person can click is often
 *   the fastest way out of a loop an agent is losing.
 * - {@see $thumbnailUpdated} — whether the listing card and the fill page now
 *   show this design. It can be false on a successful write (the picture stored
 *   fine, the row is correct) only if the object store refused the object, so
 *   it is a small honesty flag rather than a second status.
 */
readonly final class SetDesignResponse
{
    /**
     * @param bool $saved Whether the variant's canvas was replaced. False means NOTHING was written and the previous design is intact.
     * @param string $status One-sentence verdict, safe to show a human as-is.
     * @param int $errorCount Issues that BLOCKED the write; zero when saved.
     * @param int $warningCount Advisory issues; never block.
     * @param list<array<string, mixed>> $issues Every finding, in review order — each with severity, stage, code, path and an actionable message.
     * @param string $editorUrl Absolute URL of this variant in the wboost canvas editor.
     * @param bool $thumbnailUpdated Whether the stored preview thumbnail now shows this design.
     * @param null|string $format Mime type of the image block, null when nothing was drawn.
     * @param null|int $width Returned image width in pixels; null when nothing was drawn or the bytes could not be measured.
     * @param null|int $height Returned image height in pixels; null when nothing was drawn or the bytes could not be measured.
     * @param null|bool $downscaled Whether the picture is smaller than the canvas; null when nothing was drawn.
     * @param int $canvasWidth The variant's real width in canvas pixels — what the committed design renders at.
     * @param int $canvasHeight The variant's real height in canvas pixels.
     */
    public function __construct(
        public string $variantId,
        public string $templateName,
        public string $projectName,
        public bool $saved,
        public string $status,
        public int $errorCount,
        public int $warningCount,
        public array $issues,
        public string $editorUrl,
        public bool $thumbnailUpdated,
        public null|string $format,
        public null|int $width,
        public null|int $height,
        public null|bool $downscaled,
        public int $canvasWidth,
        public int $canvasHeight,
    ) {
    }
}
