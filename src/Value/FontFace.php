<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

readonly final class FontFace
{
    public function __construct(
        public string $name,
        public int $weight,
        public string $style,
        public string $filePath,
        /** Bytes of the stored file; null for faces uploaded before it was recorded. */
        public null|int $fileSize = null,
    ) {
    }

    /** The stored file's format from its extension: TTF / OTF / WOFF. */
    public function format(): string
    {
        return strtoupper(pathinfo($this->filePath, PATHINFO_EXTENSION));
    }

    /**
     * Whether this face is an italic / oblique cut. Metadata-first with a
     * NAME fallback — the same rule the fill-page WYSIWYG uses for its "I"
     * button (FontLib's subfamily is best-effort and real uploads miss it).
     */
    public function isItalic(): bool
    {
        return preg_match('/italic|oblique|kurz/i', $this->style . ' ' . $this->name) === 1;
    }

    /** "1,2 MB" / "348 kB" for the fonts page; null when the size is unknown. */
    public function fileSizeLabel(): null|string
    {
        if ($this->fileSize === null) {
            return null;
        }

        if ($this->fileSize >= 1000 * 1000) {
            return str_replace('.', ',', sprintf('%.1f', $this->fileSize / (1000 * 1000))) . ' MB';
        }

        return sprintf('%d kB', (int) round($this->fileSize / 1000));
    }
}
