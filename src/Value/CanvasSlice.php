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
     * A gap is split further around every object with a Režim prolnutí
     * ({@see CanvasBlendMode}, 2026-11-24): a flat transparent slice holds
     * the object's colours only — its blending with what lies BELOW (the
     * client's picture among it) can only happen when the fill canvas draws
     * that slice. So each blended object becomes a part of its own, tagged
     * with its mode, which the fill controller applies to the slice image;
     * consecutive normal objects still share one part. `part` numbers the
     * parts of one gap bottom-up.
     *
     * @param array<array-key, mixed> $objects decoded canvas objects[]
     * @param array<string, int> $placeholderIndexesByInputId
     * @return list<array{aboveInputId: string, part: int, slice: self, blendMode: CanvasBlendMode}>
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

            $part = 0;
            foreach (self::blendRuns($objects, $from, $to) as $run) {
                $gaps[] = [
                    'aboveInputId' => (string) $entry['inputId'],
                    'part' => $part++,
                    // The topmost run of the topmost gap stays open-ended,
                    // exactly as the unsplit slice was.
                    'slice' => new self($run['from'], $isTop && $run['to'] === $to ? null : $run['to'], withBackground: false),
                    'blendMode' => $run['blendMode'],
                ];
            }
        }

        return $gaps;
    }

    /**
     * Split [$from, $to) into paintable runs: one per blended visible object,
     * one per stretch of normal ones. Hidden objects join whatever run they
     * fall in (they paint nothing); a stretch of hidden objects only is no
     * run at all.
     *
     * @param array<array-key, mixed> $objects
     * @return list<array{from: int, to: int, blendMode: CanvasBlendMode}>
     */
    private static function blendRuns(array $objects, int $from, int $to): array
    {
        $runs = [];
        $runStart = $from;
        $runHasContent = false;

        for ($i = $from; $i < $to; $i++) {
            $object = $objects[$i] ?? null;
            if (!is_array($object) || ($object['visible'] ?? true) === false) {
                continue;
            }

            $mode = CanvasBlendMode::fromObject($object);
            if ($mode === CanvasBlendMode::Normal) {
                $runHasContent = true;

                continue;
            }

            if ($runHasContent) {
                $runs[] = ['from' => $runStart, 'to' => $i, 'blendMode' => CanvasBlendMode::Normal];
            }
            $runs[] = ['from' => $i, 'to' => $i + 1, 'blendMode' => $mode];
            $runStart = $i + 1;
            $runHasContent = false;
        }

        if ($runHasContent) {
            $runs[] = ['from' => $runStart, 'to' => $to, 'blendMode' => CanvasBlendMode::Normal];
        }

        return $runs;
    }
}
