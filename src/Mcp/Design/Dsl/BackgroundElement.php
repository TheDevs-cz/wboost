<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

/**
 * The background layer — **at most one per document** (plan §3.4 / §4.3
 * invariant 11), always compiled to stack index 0 with `isBackground: true`.
 *
 * It carries no placement on purpose: a layer-mode background is a
 * deterministic cover fit anchored top-left over the whole canvas, built by
 * `BackgroundLayer::buildObject()` — hand-rolling that transform is exactly
 * what §4.3 invariant 12 forbids.
 *
 * {@see $assetId} is optional. A layer-mode variant with no background renders
 * a TRANSPARENT PNG, and that is legal, not an error (§4.3 invariant 14).
 *
 * {@see $fillable} marks the layer a fillable image slot (the Phase-B
 * contract): it flows into `imageInputs[]` with `isBackground: true`, and
 * move/resize/rotate are forced off because the fill is a deterministic cover.
 * When the user fills nothing, the designed background renders — the stand-in
 * contract.
 */
readonly final class BackgroundElement implements DesignElement
{
    public function __construct(
        public string $id,
        /** Gallery image UUID, or null = transparent background. */
        public null|string $assetId,
        public bool $fillable,
    ) {
    }

    public function kind(): ElementKind
    {
        return ElementKind::Background;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => ElementKind::Background->value,
            'id' => $this->id,
            'asset' => $this->assetId,
            'fillable' => $this->fillable,
        ];
    }
}
