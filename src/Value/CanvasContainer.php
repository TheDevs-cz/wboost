<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A "smart text area": a designer-authored group of 2+ text placeholders that
 * reflow vertically at render time — filled text that wraps to more lines
 * pushes the members below it down (hidden members collapse), bounded by
 * {@see self::$maxHeight}. Exceeding the bound is a validation error on the
 * strict render paths (API export → 400).
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
         * top (= designed top of the first member) downward.
         */
        public float $maxHeight,
        /**
         * Member text-input UUIDs in flow order (top to bottom). The editor
         * re-derives the order from the members' vertical positions on every
         * save.
         *
         * @var list<string>
         */
        public array $memberInputIds,
    ) {
    }

    /**
     * @return array{id: string, maxHeight: float, memberInputIds: list<string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'maxHeight' => $this->maxHeight,
            'memberInputIds' => $this->memberInputIds,
        ];
    }

    /**
     * Defensive: entries that cannot reflow anything (missing id, non-positive
     * max height, fewer than 2 members) yield null and are dropped by
     * {@see self::collectionFromCanvas} — an inert container must never make a
     * render misbehave.
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
        if (count($memberInputIds) < 2) {
            return null;
        }

        return new self($id, $maxHeight, $memberInputIds);
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
}
