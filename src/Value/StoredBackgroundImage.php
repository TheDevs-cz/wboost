<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A background file written into the upload storage, together with its
 * natural pixel size (null when the bytes could not be parsed as a raster
 * image) — the design projector needs the size to bake a correct cover fit.
 */
readonly final class StoredBackgroundImage
{
    public function __construct(
        public string $path,
        public null|int $naturalWidth,
        public null|int $naturalHeight,
    ) {
    }
}
