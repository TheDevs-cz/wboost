<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * One folder of a project's image gallery.
 *
 * Folders are pure metadata — they nest, but they are not part of any image's
 * URL, so an agent never has to assemble a path. The id is the only thing the
 * next `list_gallery` call needs.
 */
readonly final class GalleryDirectoryResponse
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
