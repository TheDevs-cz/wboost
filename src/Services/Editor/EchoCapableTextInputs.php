<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Editor;

use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\EditorTextInput;

/**
 * Which of a variant's text inputs the fill page may ECHO — draw client-side
 * on a transparent canvas over the base render — instead of waiting for the
 * server render. An echo-capable input is also rendered transparent in the
 * base ({@see TemplateVariantImageRenderer::applyTransparentTexts()}), so the
 * two sets MUST be the same and this service is the single source of both.
 *
 * The rules, each protecting one way an echo could lie:
 *
 *  - `locked` inputs are design, not fill — never echoed (and their designed
 *    pixels must stay in the base).
 *  - lists-enabled rich inputs (checklists included — `checklist` forces
 *    `lists`) render as a BLOCK STACK of freshly constructed objects; the echo
 *    does not build stacks in v1, and the transparent base could not blank a
 *    stack anyway (the replacement children would not inherit the opacity).
 *  - inputs whose textbox the positional binding cannot locate have nothing
 *    to draw.
 *  - **z-guard**: the echo canvas paints ABOVE everything, so an input with
 *    any visible non-echo content stacked above AND overlapping it would have
 *    its text wrongly drawn over that content. Overlap is tested on rotated
 *    axis-aligned bounding boxes; anything the geometry cannot reason about
 *    counts as overlapping (conservative).
 *  - **container guard**: reflow moves whole container trees. A tree is only
 *    echoable when EVERY deep member is itself an echo-capable text — one
 *    baked member (a decorative icon, a settle-only text) would sit still in
 *    the base while the echoed text flows, so all texts of such a tree stay
 *    settle-rendered.
 *
 * Removing an input from the set can invalidate another (its no-longer-echoed
 * textbox becomes visible base content above a lower text; its tree loses a
 * capable member), so the rules run to a fixpoint.
 */
final readonly class EchoCapableTextInputs
{
    public function __construct(
        private TextInputObjectBinder $binder,
    ) {
    }

    /**
     * @param array<string, mixed> $canvas decoded canvas JSON
     * @param array<EditorTextInput> $inputs
     * @return list<string> echo-capable inputIds, in inputs[] order
     */
    public function resolve(array $canvas, array $inputs): array
    {
        $objects = $canvas['objects'] ?? null;
        if (!is_array($objects)) {
            return [];
        }

        $indexByInputId = [];
        foreach ($this->binder->inputIdByObjectIndex($canvas, $inputs) as $index => $inputId) {
            $indexByInputId[$inputId] ??= $index;
        }

        $candidates = [];
        foreach ($inputs as $input) {
            if ($input->locked) {
                continue;
            }
            if ($input->richText && $input->lists) {
                continue;
            }
            if (!isset($indexByInputId[$input->inputId])) {
                continue;
            }
            $candidates[$input->inputId] = true;
        }

        if ($candidates === []) {
            return [];
        }

        $containers = CanvasContainer::collectionFromCanvas($canvas);
        $bboxByIndex = $this->boundingBoxes($objects);

        // Fixpoint: each pass may demote candidates, which can create new
        // visible-above content or break a container tree for the next pass.
        do {
            $removed = false;

            foreach (array_keys($candidates) as $inputId) {
                if ($this->hasVisibleNonEchoContentAbove($inputId, $indexByInputId, $objects, $bboxByIndex, $candidates)) {
                    unset($candidates[$inputId]);
                    $removed = true;
                }
            }

            foreach ($this->containerTrees($containers) as $treeMemberIds) {
                $treeIsClean = true;
                foreach ($treeMemberIds as $memberId) {
                    if (!isset($candidates[$memberId])) {
                        $treeIsClean = false;
                        break;
                    }
                }
                if ($treeIsClean) {
                    continue;
                }
                foreach ($treeMemberIds as $memberId) {
                    if (isset($candidates[$memberId])) {
                        unset($candidates[$memberId]);
                        $removed = true;
                    }
                }
            }
        } while ($removed && $candidates !== []);

        $result = [];
        foreach ($inputs as $input) {
            if (isset($candidates[$input->inputId]) && !in_array($input->inputId, $result, true)) {
                $result[] = $input->inputId;
            }
        }

        return $result;
    }

    /**
     * @param array<string, int> $indexByInputId
     * @param array<array-key, mixed> $objects
     * @param array<int, null|array{float, float, float, float}> $bboxByIndex
     * @param array<string, true> $candidates
     */
    private function hasVisibleNonEchoContentAbove(
        string $inputId,
        array $indexByInputId,
        array $objects,
        array $bboxByIndex,
        array $candidates,
    ): bool {
        $ownIndex = $indexByInputId[$inputId];
        $ownBox = $bboxByIndex[$ownIndex] ?? null;
        if ($ownBox === null) {
            return true; // cannot reason about its own geometry
        }

        $capableIndexes = [];
        foreach ($candidates as $candidateId => $_) {
            if (isset($indexByInputId[$candidateId])) {
                $capableIndexes[$indexByInputId[$candidateId]] = true;
            }
        }

        $count = count($objects);
        for ($i = $ownIndex + 1; $i < $count; $i++) {
            $object = $objects[$i] ?? null;
            if (!is_array($object)) {
                return true; // unknown shape above — refuse to reason
            }
            if (($object['visible'] ?? true) === false) {
                continue; // design-hidden paints nothing
            }
            if (isset($capableIndexes[$i])) {
                continue; // another echoed text — the echo canvas keeps their mutual order
            }

            $box = $bboxByIndex[$i] ?? null;
            if ($box === null || self::intersects($ownBox, $box)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deep member-id sets per ROOT container tree. Sibling collision-push can
     * chain any two vertically stacked roots, but only through their members —
     * so per-tree cleanliness is the right granularity: a dirty tree's members
     * simply stay settle-rendered and the base carries their (override-aware)
     * pixels, while a clean tree reflows purely invisible objects.
     *
     * @param list<CanvasContainer> $containers
     * @return list<list<string>>
     */
    private function containerTrees(array $containers): array
    {
        $byId = [];
        foreach ($containers as $container) {
            $byId[$container->id] = $container;
        }

        $trees = [];
        foreach ($containers as $container) {
            if ($container->isNestedIn($containers)) {
                continue;
            }
            $memberIds = [];
            $this->collectTreeMembers($container, $byId, $memberIds, []);
            $trees[] = array_values(array_unique($memberIds));
        }

        return $trees;
    }

    /**
     * @param array<string, CanvasContainer> $byId
     * @param list<string> $memberIds
     * @param list<string> $visited guards defensively against cycles
     */
    private function collectTreeMembers(CanvasContainer $container, array $byId, array &$memberIds, array $visited): void
    {
        if (in_array($container->id, $visited, true)) {
            return;
        }
        $visited[] = $container->id;

        foreach ($container->memberInputIds as $memberId) {
            $memberIds[] = $memberId;
        }
        foreach ($container->memberContainerIds as $childId) {
            if (isset($byId[$childId])) {
                $this->collectTreeMembers($byId[$childId], $byId, $memberIds, $visited);
            }
        }
    }

    /**
     * Rotation-aware axis-aligned bounding box per object index, or null when
     * the object's geometry cannot be derived (treated as overlapping by the
     * caller). Fabric rotates around the origin anchor (left/top per
     * originX/originY), so the corners are rotated about that anchor.
     *
     * @param array<array-key, mixed> $objects
     * @return array<int, null|array{float, float, float, float}> [x1, y1, x2, y2]
     */
    private function boundingBoxes(array $objects): array
    {
        $boxes = [];

        foreach ($objects as $index => $object) {
            if (!is_int($index)) {
                continue;
            }
            $boxes[$index] = is_array($object) ? self::rotatedAabb($object) : null;
        }

        return $boxes;
    }

    /**
     * @param array<array-key, mixed> $object
     * @return null|array{float, float, float, float}
     */
    private static function rotatedAabb(array $object): null|array
    {
        $width = self::toFloat($object['width'] ?? null);
        $height = self::toFloat($object['height'] ?? null);
        if ($width === null || $height === null || $width <= 0.0 || $height <= 0.0) {
            return null;
        }

        $left = self::toFloat($object['left'] ?? null) ?? 0.0;
        $top = self::toFloat($object['top'] ?? null) ?? 0.0;
        $scaleX = self::toFloat($object['scaleX'] ?? null) ?? 1.0;
        $scaleY = self::toFloat($object['scaleY'] ?? null) ?? 1.0;
        $angle = self::toFloat($object['angle'] ?? null) ?? 0.0;

        $w = $width * $scaleX;
        $h = $height * $scaleY;

        $originX = is_string($object['originX'] ?? null) ? $object['originX'] : 'left';
        $originY = is_string($object['originY'] ?? null) ? $object['originY'] : 'top';
        $ox = match ($originX) {
            'center' => $w / 2,
            'right' => $w,
            default => 0.0,
        };
        $oy = match ($originY) {
            'center' => $h / 2,
            'bottom' => $h,
            default => 0.0,
        };

        $rad = deg2rad($angle);
        $cos = cos($rad);
        $sin = sin($rad);

        $minX = $minY = PHP_FLOAT_MAX;
        $maxX = $maxY = -PHP_FLOAT_MAX;
        foreach ([[-$ox, -$oy], [$w - $ox, -$oy], [-$ox, $h - $oy], [$w - $ox, $h - $oy]] as [$cx, $cy]) {
            $x = $left + $cx * $cos - $cy * $sin;
            $y = $top + $cx * $sin + $cy * $cos;
            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);
        }

        return [$minX, $minY, $maxX, $maxY];
    }

    /**
     * @param array{float, float, float, float} $a
     * @param array{float, float, float, float} $b
     */
    private static function intersects(array $a, array $b): bool
    {
        return $a[0] < $b[2] && $a[2] > $b[0] && $a[1] < $b[3] && $a[3] > $b[1];
    }

    private static function toFloat(mixed $value): null|float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
