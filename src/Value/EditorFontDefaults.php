<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * What the brand manuals say about the project's fonts, as the canvas
 * editor consumes it: the face a NEW text should start in, and the faces the
 * manuals enable (the "Použít řezy z manuálu" preset for an input's font
 * allowlist). Resolved by {@see \WBoost\Web\Services\Editor\ResolveEditorFontDefaults}.
 */
readonly final class EditorFontDefaults
{
    /**
     * @param null|string $defaultFamily exact `"<Font> (<Face>)"` family for
     *   new texts — the primary manual font's regular cut; null when the
     *   project has no fonts at all
     * @param list<string> $manualFaces every face family some manual enables
     *   (primary fonts first, then secondary), deduped
     */
    public function __construct(
        public null|string $defaultFamily,
        public array $manualFaces,
    ) {
    }
}
