<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * One fillable IMAGE placeholder of a variant — the picture counterpart of
 * {@see VariantTextInputResponse}, addressed by the same kind of stable `id`.
 *
 * A fill names a gallery image; the server fits it into {@see $frame}
 * (object-contain, centred, clipped to the box). `allowMove` / `allowResize` /
 * `allowRotate` say which adjustments the slot accepts — sending one it
 * forbids is rejected, not silently dropped — and `hidable` says whether the
 * slot may be blanked instead.
 *
 * **Where the picture may come from** is two fields together, and reading only
 * one of them gets it wrong. `directories` are the folders the image must be
 * in; `includesRoot` says images that sit in NO folder are allowed too. Both
 * open (a full folder list plus `includesRoot: true`) is the unrestricted
 * slot — the whole project gallery. An empty `directories` with
 * `includesRoot: false` is the one genuine dead end: every folder the designer
 * allowed has since been deleted.
 *
 * `isBackground` marks the variant's background layer. Its fill is fixed —
 * cover-fitted over the whole canvas, anchored top-left, no transform accepted
 * (which is why the three allow flags are always false there) — and its
 * `frame` is the full canvas, not the designed object's box. Leave it unfilled
 * and the designed background renders.
 */
readonly final class VariantImageInputResponse
{
    /**
     * @param list<VariantImageDirectoryResponse> $directories
     */
    public function __construct(
        public string $id,
        public null|string $name,
        public null|string $description,
        public bool $isBackground,
        public bool $allowMove,
        public bool $allowResize,
        public bool $allowRotate,
        public bool $hidable,
        public array $directories,
        public bool $includesRoot,
        public null|VariantInputFrameResponse $frame,
    ) {
    }
}
