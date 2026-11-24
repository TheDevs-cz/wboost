<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * "Navázat pod" (2026-11-24): a TOP-LEVEL container attached below another
 * top-level container. At render time the follower lands {@see $gap} px below
 * the target's ACTUAL content bottom — rows added or removed, texts wrapping,
 * members hidden: it is pulled up AND pushed down (plain collision-push only
 * ever pushes down). Chains are allowed (C below B below A); a fully collapsed
 * target hands over its own top. Both containers keep their own maxHeight and
 * settings — the explicit "document flow without nesting".
 *
 * Stored on the FOLLOWER's definition in `canvas.containers[]` as
 * `"follows": {"containerId": "<target>", "gap": 20}` — emitted only when
 * set, so every other container keeps its exact JSON. The structural rules
 * (both ends top-level, the target exists, no cycles) need the whole
 * collection and live in {@see CanvasContainer::collectionFromCanvas()}; this
 * VO validates the shape alone. The layout itself is the shared engine's
 * (assets/editor/container_layout.js `applyFabricLayout`), mirrored by
 * {@see \WBoost\Web\Services\Template\DesignedContainerFlow}.
 */
final readonly class ContainerFollow
{
    public function __construct(
        public string $containerId,
        public float $gap,
    ) {
    }

    /**
     * Defensive parse (≡ JS `normalizeFollows`): a non-empty `containerId`
     * other than the container's own id; `gap` finite ≥ 0, anything else 0.
     */
    public static function fromArray(mixed $data, string $selfId): null|self
    {
        if (!is_array($data)) {
            return null;
        }

        $containerId = $data['containerId'] ?? null;
        if (!is_string($containerId) || $containerId === '' || $containerId === $selfId) {
            return null;
        }

        $gap = $data['gap'] ?? null;
        $gap = (is_int($gap) || is_float($gap)) && is_finite((float) $gap) && $gap >= 0 ? (float) $gap : 0.0;

        return new self($containerId, $gap);
    }

    /**
     * @return array{containerId: string, gap: float}
     */
    public function toArray(): array
    {
        return ['containerId' => $this->containerId, 'gap' => $this->gap];
    }
}
