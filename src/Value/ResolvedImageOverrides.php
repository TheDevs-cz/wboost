<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * Image override maps for a render call, keyed by the placeholder's `inputId`
 * UUID — the image counterpart of {@see ResolvedInputOverrides}. A slot is in
 * exactly one of the two maps (or neither, leaving the designer's stand-in):
 * `images` carries a chosen picture + transform, `hidden` blanks the slot.
 */
readonly final class ResolvedImageOverrides
{
    /**
     * @param array<string, ResolvedImageOverride> $images inputId → chosen image + transform.
     * @param array<string, bool> $hidden inputId → true when the placeholder should be hidden.
     */
    public function __construct(
        public array $images,
        public array $hidden,
    ) {
    }

    public static function none(): self
    {
        return new self([], []);
    }
}
