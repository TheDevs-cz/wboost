<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

/**
 * A shared field: ONE value (`fields[id]` in the export body) filled into
 * every bound input across the pages.
 */
final readonly class TemplateDocumentFieldResponse
{
    /**
     * @param list<TemplateDocumentFieldBindingResponse> $bindings
     */
    public function __construct(
        public string $id,
        public string $name,
        /** `text` (a plain string) | `image` (a gallery image id, or `{imageId, hide}`). */
        public string $kind,
        public array $bindings,
        /** The smallest `maxLength` of the bound text inputs (null = unlimited / image). */
        public null|int $maxLength,
        /**
         * Text: EVERY valid bound input is `richText` — the field may carry a
         * rich value (`{runs}`); otherwise send a plain string / `{value}`.
         * Font choices stay per input: intersect `pages[].variant.inputs[].fontOptions`.
         */
        public bool $richText = false,
        /** EVERY valid bound input / slot is `hidable` — `{hide: true}` hides it on all pages. */
        public bool $hidable = false,
        /**
         * Image: the gallery folders a pick may come from — the intersection
         * of the bound slots' `allowedDirectoryIds`. `null` = any folder;
         * `[]` = the slots share no folder (no pick passes every page).
         * Always null for text.
         *
         * @var null|list<string>
         */
        public null|array $allowedDirectoryIds = null,
    ) {
    }
}
