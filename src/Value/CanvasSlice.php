<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A horizontal cut through a canvas document's paint stack: only the objects
 * whose index falls inside [fromIndex, toIndex) stay visible, everything else
 * is suppressed. Used by the fill page's hybrid preview to decompose one
 * design into z-ordered layers around its image placeholders — the backdrop
 * (everything BELOW the lowest placeholder, background included) plus one
 * transparent overlay per gap that actually holds content — so the live
 * Fabric objects the user positions can be stacked at exactly the designed
 * z-position instead of always painting on top.
 *
 * Suppression means `opacity: 0`, NOT `visible: false`, and that is
 * load-bearing: invisible objects drop out of the positional textbox↔input
 * binding and of container membership (collectMembers skips them), which
 * would reflow the remaining texts differently per slice. An opacity-0
 * object keeps its exact layout influence, so every slice renders its
 * objects at pixel-identical positions to the full render.
 */
final readonly class CanvasSlice
{
    public function __construct(
        public int $fromIndex,
        public null|int $toIndex,
        public bool $withBackground,
    ) {
    }

    /**
     * The overlay slices a fill-page preview needs above its image
     * placeholders: for each placeholder (ascending stack index), the gap up
     * to the next placeholder (or the top of the stack). Gaps without any
     * paintable object (empty, or designer-hidden objects only) are skipped —
     * the typical "everything sits below the placeholders" design costs no
     * extra renders. Content BELOW the lowest placeholder is not a gap; it
     * belongs to the backdrop slice.
     *
     * @param array<array-key, mixed> $objects decoded canvas objects[]
     * @param array<string, int> $placeholderIndexesByInputId
     * @return list<array{aboveInputId: string, slice: self}>
     */
    public static function overlayGapsAbovePlaceholders(array $objects, array $placeholderIndexesByInputId): array
    {
        asort($placeholderIndexesByInputId);

        $entries = [];
        foreach ($placeholderIndexesByInputId as $inputId => $index) {
            $entries[] = ['inputId' => $inputId, 'index' => $index];
        }

        $objectCount = count($objects);
        $gaps = [];

        foreach ($entries as $position => $entry) {
            $from = $entry['index'] + 1;
            $isTop = !isset($entries[$position + 1]);
            $to = $isTop ? $objectCount : $entries[$position + 1]['index'];

            $hasContent = false;
            for ($i = $from; $i < $to; $i++) {
                $object = $objects[$i] ?? null;
                if (is_array($object) && ($object['visible'] ?? true) !== false) {
                    $hasContent = true;
                    break;
                }
            }

            if (!$hasContent) {
                continue;
            }

            $gaps[] = [
                'aboveInputId' => $entry['inputId'],
                'slice' => new self($from, $isTop ? null : $to, withBackground: false),
            ];
        }

        return $gaps;
    }
}
