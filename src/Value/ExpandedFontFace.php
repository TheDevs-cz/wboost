<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * One static cut instanced out of a variable font, on local disk until the
 * upload path has stored it (see VariableFontExpander).
 */
readonly final class ExpandedFontFace
{
    public function __construct(
        /** Absolute path of the static font file. */
        public string $path,
        /** "Inter-ExtraBold.ttf" — the name a foundry ships this cut under. */
        public string $clientName,
        /** "Inter ExtraBold" — nameID 4, which becomes the face name. */
        public string $fullName,
        public int $weight,
        public bool $italic,
    ) {
    }
}
