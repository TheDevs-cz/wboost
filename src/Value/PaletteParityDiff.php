<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * One difference `app:palette:verify` found between the read model R1 code
 * builds from the manual JSONB columns and the one R2 serves from the palette
 * tables. `accepted` = a change the owner signed off (palette-plan.md §6/§7):
 * D2 roles, §7 HEX spelling + fill the gap, D5 palette order, never-delete;
 * anything else is a sync bug — and since R2 every per-manual difference also
 * means the JSONB mirror is stale (`jsonb_mirror_stale`, reconcile repairs it).
 */
readonly final class PaletteParityDiff
{
    public const array ACCEPTED_KINDS = [
        'role' => 'D2 — one role per project colour; this manual typed it differently',
        'role_pages' => 'D2 — primary/secondary colour pages follow the palette role (or drop a stale detected colour)',
        'custom_hex_spelling' => '§7 — custom colour prints the palette HEX spelling',
        'print_gap_filled' => '§7 — fill the gap: the merged palette colour carries a value this manual left empty',
        'print_whitespace' => '§2 — print data merged up to whitespace',
        'palette_order' => 'D5 — palette order is authoritative (seeded = computeColors walk; drifts after role edits)',
        'unused_palette_colour' => 'palette colours are never deleted — no manual links it any more, so it is no swatch (GetProjectPalette)',
        'cmyk_first_wins_order' => 'conflicting CMYK across manuals today: "first wins" follows palette order',
        'jsonb_unreadable' => 'the JSONB read model throws today (reported by the planner, never auto-fixed)',
    ];

    public function __construct(
        public string $projectId,
        public string $project,
        public null|string $manualId,
        public null|string $manual,
        public string $kind,
        public string $detail,
    ) {
    }

    public function accepted(): bool
    {
        return isset(self::ACCEPTED_KINDS[$this->kind]);
    }
}
