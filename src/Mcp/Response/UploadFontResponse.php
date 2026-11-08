<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * The reply of the `upload_font` MCP tool. Every face is reported as the
 * exact font string a design document's `font` (and `input.allowedFonts`)
 * takes — `"Inter (Inter ExtraBold)"`, the {@see \WBoost\Web\Entity\Font::faceFamily()}
 * spelling `get_context` lists — so an agent can design with a new cut
 * straight away.
 */
readonly final class UploadFontResponse
{
    /**
     * @param list<string> $created faces this upload added
     * @param list<string> $alreadyPresent faces the family already had (left untouched)
     * @param list<string> $fonts every face of the family after the upload
     */
    public function __construct(
        public null|string $family,
        public bool $variable,
        public array $created,
        public array $alreadyPresent,
        public array $fonts,
        public string $message,
    ) {
    }
}
