<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

/**
 * The `input` block of an image element — the fillable-slot definition that
 * compiles to an {@see \WBoost\Web\Value\EditorImageInput}.
 *
 * **Presence is meaningful here, unlike {@see TextInputSpec}.** An image is a
 * fillable placeholder only when it is marked one (plan §4.1 invariant 4:
 * `imageInputs[]` holds image objects with `imagePlaceholder: true`), and
 * decorative images have a real job of their own — they may be container
 * members, where fillable placeholders and the background layer may not
 * (§4.4 invariant 18). So:
 *
 * - no `input` block  → decorative image, never fillable;
 * - `input` present   → fillable slot ({@see $placeholder} defaults to TRUE —
 *   describing a slot's limits and then not getting a slot would be absurd);
 * - `input: { "placeholder": false, … }` → explicit opt-out, e.g. to give a
 *   decorative image a name without making it fillable.
 */
readonly final class ImageInputSpec
{
    public function __construct(
        /** Label shown on the fill page / in `describe_variant`. Null = unnamed. */
        public null|string $name = null,
        /** Whether this image is a fillable slot at all. */
        public bool $placeholder = true,
        /** The user may pan their picture inside the frame. */
        public bool $allowMove = true,
        /** The user may scale their picture inside the frame. */
        public bool $allowResize = true,
        /** The user may rotate their picture inside the frame. */
        public bool $allowRotate = false,
        /** The user may hide this element entirely. */
        public bool $hidable = false,
        /**
         * Gallery folder ids (UUIDs) this slot may be filled from.
         *
         * **Empty = UNRESTRICTED — the whole gallery including the root**, never
         * "none". That is the app-wide semantic owned by
         * {@see \WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories};
         * naming any folder implicitly excludes the root.
         *
         * @var list<string>
         */
        public array $allowedDirectories = [],
    ) {
    }

    /**
     * @return array{name: null|string, placeholder: bool, allowMove: bool, allowResize: bool, allowRotate: bool, hidable: bool, allowedDirectories: list<string>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'placeholder' => $this->placeholder,
            'allowMove' => $this->allowMove,
            'allowResize' => $this->allowResize,
            'allowRotate' => $this->allowRotate,
            'hidable' => $this->hidable,
            'allowedDirectories' => $this->allowedDirectories,
        ];
    }
}
