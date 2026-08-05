<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * The EFFECTIVE list styling of a lists-enabled rich input: the admin's
 * explicit values with the derived defaults filled in from the input's
 * designed text style. This is the single source of truth for the default
 * formulas — the JS mirror lives in `assets/editor/rich_text_blocks.js`
 * (effectiveConfig) and the API exposes these RESOLVED pixel values so
 * consumers never re-derive them.
 *
 * Defaults: indent = 1.5 × fontSize (bullet gutter), itemSpacing = 0 (the
 * line height already separates items, matching how the same lines rendered
 * before lists existed), blockSpacing = 0.5 × (fontSize × lineHeight) — a
 * visible paragraph break.
 */
readonly final class ResolvedListStyle
{
    public const array BULLETS = ['disc', 'dash', 'check', 'image'];

    private function __construct(
        public string $bullet,
        public null|string $bulletImage,
        public float $indent,
        public float $itemSpacing,
        public float $blockSpacing,
        public bool $checkboxes,
        public null|string $checkboxImage,
        public null|string $checkboxCheckedImage,
    ) {
    }

    public static function resolve(EditorTextInput $input, float $fontSize, float $lineHeight): self
    {
        $bullet = $input->listBullet ?? 'disc';
        $bulletImage = $input->listBulletImage;

        // An image bullet without a picked image degrades to the disc — the
        // render must never depend on an unset asset.
        if ($bullet === 'image' && $bulletImage === null) {
            $bullet = 'disc';
        }
        if ($bullet !== 'image') {
            $bulletImage = null;
        }

        return new self(
            bullet: $bullet,
            bulletImage: $bulletImage,
            indent: $input->listIndent ?? round($fontSize * 1.5, 1),
            itemSpacing: $input->listItemSpacing ?? 0.0,
            blockSpacing: $input->listBlockSpacing ?? round($fontSize * $lineHeight * 0.5, 1),
            // Checkbox styling only matters when the input allows checkbox
            // lists; a null image = the DEFAULT drawn checkbox (rounded
            // square in the item's text color, checked adds a white check).
            checkboxes: $input->listCheckboxes,
            checkboxImage: $input->listCheckboxes ? $input->listCheckboxImage : null,
            checkboxCheckedImage: $input->listCheckboxes ? $input->listCheckboxCheckedImage : null,
        );
    }

    /**
     * @return array{bullet: string, bulletImage: null|string, indent: float, itemSpacing: float, blockSpacing: float, checkboxes: bool, checkboxImage: null|string, checkboxCheckedImage: null|string}
     */
    public function toArray(): array
    {
        return [
            'bullet' => $this->bullet,
            'bulletImage' => $this->bulletImage,
            'indent' => $this->indent,
            'itemSpacing' => $this->itemSpacing,
            'blockSpacing' => $this->blockSpacing,
            'checkboxes' => $this->checkboxes,
            'checkboxImage' => $this->checkboxImage,
            'checkboxCheckedImage' => $this->checkboxCheckedImage,
        ];
    }
}
