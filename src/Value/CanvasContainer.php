<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A "smart text area": a designer-authored group of members that reflow
 * vertically at render time — filled text that wraps to more lines pushes the
 * flow items below it down (hidden items collapse), bounded by
 * {@see self::$maxHeight}. Exceeding the bound is a validation error on the
 * strict render paths (API export → 400).
 *
 * Members are text placeholders and decorative images ({@see $memberInputIds})
 * plus, since the nesting rework, other containers ({@see $memberContainerIds}):
 * a child container is laid out first and flows in its parent as ONE item —
 * only the outermost (root) container's maxHeight bounds the flow, a nested
 * container grows freely with its content. {@see $gap} optionally replaces the
 * designed inter-item gaps with a uniform spacing.
 *
 * Containers are persisted as a top-level `containers` key INSIDE the canvas
 * JSONB document (not a separate column): the canvas string already travels
 * untouched through the whole save pipeline, the copy handlers, and every
 * consumer that needs container data (renderer, fill page, API providers)
 * already decodes the canvas. The layout algorithm itself lives in
 * assets/editor/container_layout.js — the single JS source of truth shared by
 * the headless render, the admin editor and the fill page; this VO only
 * carries the definition.
 */
readonly final class CanvasContainer
{
    public function __construct(
        public string $id,
        /**
         * Maximum content height in canvas px, measured from the container's
         * top (= designed top of the first flow item) downward. Only binding
         * for a container that is NOT nested inside another one.
         */
        public float $maxHeight,
        /**
         * Member input UUIDs (texts + decorative images) in flow order (top
         * to bottom). The editor re-derives the order from the members'
         * vertical positions on every save.
         *
         * @var list<string>
         */
        public array $memberInputIds,
        /**
         * Child container ids nested inside this one (each flows as one item).
         *
         * @var list<string>
         */
        public array $memberContainerIds = [],
        /**
         * Uniform inter-item spacing in canvas px; null = the designed gaps
         * between the members are preserved (pre-nesting behavior).
         */
        public null|float $gap = null,
        /**
         * Guaranteed minimum clearance BELOW the container in canvas px:
         * the landing distance when it pushes a sibling, the floor of the
         * following gap when nested, and its page-bottom margin. Null = 0.
         */
        public null|float $spaceAfter = null,
    ) {
    }

    /**
     * @return array{id: string, maxHeight: float, memberInputIds: list<string>, memberContainerIds: list<string>, gap: null|float, spaceAfter: null|float}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'maxHeight' => $this->maxHeight,
            'memberInputIds' => $this->memberInputIds,
            'memberContainerIds' => $this->memberContainerIds,
            'gap' => $this->gap,
            'spaceAfter' => $this->spaceAfter,
        ];
    }

    /**
     * Defensive: entries that cannot reflow anything (missing id, non-positive
     * max height, fewer than 2 members counting nested containers) yield null
     * and are dropped by {@see self::collectionFromCanvas} — an inert
     * container must never make a render misbehave. Invalid `gap` values fall
     * back to designed gaps; child references are sanity-filtered here only
     * lightly (strings) — cycle/one-parent enforcement happens at save time in
     * the editor and defensively in the shared layout engine.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): null|self
    {
        $id = $data['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }

        $maxHeight = $data['maxHeight'] ?? null;
        if (!is_int($maxHeight) && !is_float($maxHeight)) {
            return null;
        }
        $maxHeight = (float) $maxHeight;
        if ($maxHeight <= 0.0) {
            return null;
        }

        $memberInputIds = [];
        $rawMembers = $data['memberInputIds'] ?? null;
        if (is_array($rawMembers)) {
            foreach ($rawMembers as $memberId) {
                if (is_string($memberId) && $memberId !== '') {
                    $memberInputIds[] = $memberId;
                }
            }
        }

        $memberContainerIds = [];
        $rawChildren = $data['memberContainerIds'] ?? null;
        if (is_array($rawChildren)) {
            foreach ($rawChildren as $childId) {
                if (is_string($childId) && $childId !== '' && $childId !== $id) {
                    $memberContainerIds[] = $childId;
                }
            }
        }

        if (count($memberInputIds) + count($memberContainerIds) < 2) {
            return null;
        }

        return new self(
            $id,
            $maxHeight,
            $memberInputIds,
            $memberContainerIds,
            self::nonNegativeFloatOrNull($data['gap'] ?? null),
            self::nonNegativeFloatOrNull($data['spaceAfter'] ?? null),
        );
    }

    private static function nonNegativeFloatOrNull(mixed $value): null|float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $value = (float) $value;

        return ($value < 0.0 || !is_finite($value)) ? null : $value;
    }

    /**
     * Extract the container definitions from a decoded canvas document.
     *
     * @param array<array-key, mixed> $canvas
     * @return list<self>
     */
    public static function collectionFromCanvas(array $canvas): array
    {
        $rawContainers = $canvas['containers'] ?? null;
        if (!is_array($rawContainers)) {
            return [];
        }

        $collection = [];
        foreach ($rawContainers as $rawContainer) {
            if (!is_array($rawContainer)) {
                continue;
            }
            $container = self::fromArray($rawContainer);
            if ($container !== null) {
                $collection[] = $container;
            }
        }

        return $collection;
    }

    /**
     * Is this container nested inside another one of the given collection?
     * (Nested containers grow freely — only roots bound the flow.)
     *
     * @param list<self> $containers
     */
    public function isNestedIn(array $containers): bool
    {
        foreach ($containers as $candidate) {
            if ($candidate->id !== $this->id && in_array($this->id, $candidate->memberContainerIds, true)) {
                return true;
            }
        }

        return false;
    }
}
