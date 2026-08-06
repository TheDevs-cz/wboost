<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

/**
 * An image element — a Fabric image object, either decorative or a fillable
 * placeholder ({@see ImageInputSpec}).
 *
 * {@see $assetId} is a gallery image id (a `FileUpload` UUID as returned by
 * `list_gallery` / `upload_image`), never a filename or a URL — the compiler
 * needs the row to emit both `src` and `assetPath`, without which
 * `AssetInliner` cannot inline the picture for headless Chromium (plan §4.2
 * invariant 9). It is optional: a placeholder may ship with no stand-in
 * picture at all.
 *
 * An image with neither an asset nor a placeholder input renders nothing and
 * fills nothing. That is a LINT (S4-T6), not a parse error: it is a judgement
 * about intent, and unlike a 1-member container it survives the compile
 * intact, so the agent can still see it in the preview it gets back.
 */
readonly final class ImageElement implements DesignElement
{
    public function __construct(
        public string $id,
        /** Gallery image UUID, or null for an empty slot. */
        public null|string $assetId,
        public Placement $placement,
        /** Null = decorative image (never fillable). */
        public null|ImageInputSpec $input,
    ) {
    }

    public function kind(): ElementKind
    {
        return ElementKind::Image;
    }

    /**
     * Is this a fillable slot? (`input` present AND not explicitly opted out.)
     *
     * Load-bearing for the container rules: a fillable placeholder may never
     * be a container member (plan §4.4 invariant 18), a decorative image may.
     */
    public function isPlaceholder(): bool
    {
        return $this->input !== null && $this->input->placeholder;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $placement = $this->placement->toArray();

        return [
            'kind' => ElementKind::Image->value,
            'id' => $this->id,
            'asset' => $this->assetId,
            'at' => $placement['at'],
            'x' => $placement['x'],
            'y' => $placement['y'],
            'width' => $placement['width'],
            'height' => $placement['height'],
            'input' => $this->input?->toArray(),
        ];
    }
}
