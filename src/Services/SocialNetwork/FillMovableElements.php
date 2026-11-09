<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\ElementMoveAllowance;
use WBoost\Web\Value\MovableElement;

/**
 * The fill page's view of the user-movable elements ("Uživatel může
 * posunout", axis-move plan M3): one row per element of
 * {@see ResolveElementOffsets::movableElementsOf()} — the single eligibility
 * source — carrying everything the move tool needs WITHOUT recomputing it in
 * the browser:
 *
 * - `frame`: the SERVER's designed frame ({@see MovableElement::$frame},
 *   Fabric's bounding rect on the EXPANDED canvas; a container's = the union
 *   of its visible deep members) in canvas px, renamed to the overlay's
 *   `{x, y, width, height}`;
 * - `bounds`: the allowed offset range ({@see MovableElement::bounds()}) —
 *   the client clamps with exactly the numbers the lenient resolver clamps
 *   with, so a client-side position is never re-clamped into another one;
 * - `members`: a container's deep member inputIds (its move-box outline
 *   follows their reflowed frames);
 * - `echo`: the client text echo can repaint the element live (an
 *   echo-capable text, or a container whose every member is one) — all
 *   other kinds show an outline + ghost and wait for the settle;
 * - `hints`: the per-axis limit captions in the variant's unit.
 *
 * Rows are keyed by element id, sorted by id; empty for every design
 * without movable elements.
 */
final readonly class FillMovableElements
{
    private const array KIND_LABELS = [
        MovableElement::KIND_TEXT => 'Text',
        MovableElement::KIND_SHAPE => 'Tvar',
        MovableElement::KIND_IMAGE => 'Obrázek',
        MovableElement::KIND_CONTAINER => 'Skupina',
    ];

    /**
     * @param list<string> $echoIds the variant's echo-capable text inputIds
     *
     * @return array<string, array{
     *     id: string,
     *     kind: string,
     *     name: string,
     *     frame: array{x: float, y: float, width: float, height: float},
     *     allowance: array{vertical: bool, horizontal: bool, maxUp: null|float, maxDown: null|float, maxLeft: null|float, maxRight: null|float},
     *     bounds: array{minX: float, maxX: float, minY: float, maxY: float},
     *     members: list<string>,
     *     echo: bool,
     *     hints: array{vertical: null|string, horizontal: null|string}
     * }>
     */
    public function forVariant(TemplateVariant $variant, array $echoIds): array
    {
        $movable = ResolveElementOffsets::movableElementsOf($variant);
        if ($movable === []) {
            return [];
        }

        $decoded = json_decode($variant->canvas, true);
        $canvas = is_array($decoded) ? $decoded : [];
        $containers = CanvasContainer::collectionFromCanvas($canvas);
        $containersById = [];
        foreach ($containers as $container) {
            $containersById[$container->id] ??= $container;
        }

        $inputNames = [];
        foreach ($variant->inputs as $input) {
            $inputNames[$input->inputId] = $input->name;
        }
        $objectNames = self::objectNames($canvas);

        $echoSet = array_flip($echoIds);
        $width = (float) $variant->dimension->width();
        $height = (float) $variant->dimension->height();
        $unit = $variant->dimension->unit;

        $rows = [];
        foreach ($movable as $id => $element) {
            $members = [];
            $name = null;
            if ($element->kind === MovableElement::KIND_CONTAINER) {
                $container = $containersById[$id] ?? null;
                $members = $container?->deepMemberInputIds($containers) ?? [];
                $name = $container?->name;
                $echo = $members !== [] && array_diff($members, $echoIds) === [];
            } else {
                $name = $inputNames[$id] ?? $objectNames[$id] ?? null;
                $echo = isset($echoSet[$id]);
            }

            $allowance = $element->allowance;
            $rows[$id] = [
                'id' => $id,
                'kind' => $element->kind,
                'name' => is_string($name) && trim($name) !== '' ? $name : self::KIND_LABELS[$element->kind] ?? 'Prvek',
                'frame' => [
                    'x' => $element->frame['left'],
                    'y' => $element->frame['top'],
                    'width' => $element->frame['width'],
                    'height' => $element->frame['height'],
                ],
                'allowance' => $allowance->toArray(),
                'bounds' => $element->bounds($width, $height),
                'members' => $members,
                'echo' => $echo,
                'hints' => [
                    'vertical' => $allowance->vertical
                        ? self::hint('nahoru', $allowance->maxUp, 'dolů', $allowance->maxDown, $unit)
                        : null,
                    'horizontal' => $allowance->horizontal
                        ? self::hint('doleva', $allowance->maxLeft, 'doprava', $allowance->maxRight, $unit)
                        : null,
                ],
            ];
        }

        return $rows;
    }

    /**
     * "nahoru až 15 mm · dolů bez omezení" — null = no limit (the element
     * still stays on the artwork), 0 = not that way.
     */
    private static function hint(string $lessLabel, null|float $less, string $moreLabel, null|float $more, DimensionUnit $unit): string
    {
        return self::direction($lessLabel, $less, $unit) . ' · ' . self::direction($moreLabel, $more, $unit);
    }

    private static function direction(string $label, null|float $max, DimensionUnit $unit): string
    {
        if ($max === null) {
            return $label . ' bez omezení';
        }
        if ($max <= 0.0) {
            return $label . ' ne';
        }

        return $label . ' až ' . self::format($max, $unit);
    }

    /**
     * Canvas px → the variant's unit (300 DPI for mm / cm), Czech decimal
     * comma, trailing zeros dropped — the image adjust panel's precision
     * (px whole, mm one decimal, cm two).
     */
    public static function format(float $px, DimensionUnit $unit): string
    {
        [$value, $decimals] = match ($unit) {
            DimensionUnit::Px => [$px, 0],
            DimensionUnit::Mm => [$px * 25.4 / DimensionUnit::PRINT_DPI, 1],
            DimensionUnit::Cm => [$px * 2.54 / DimensionUnit::PRINT_DPI, 2],
        };

        $text = number_format($value, $decimals, ',', "\u{00A0}");
        if ($decimals > 0) {
            $text = rtrim(rtrim($text, '0'), ',');
        }

        return $text . "\u{00A0}" . $unit->value;
    }

    /**
     * The designer's layer names (`name` custom prop) by inputId — the
     * caption of a decorative image / plain shape / locked text box.
     *
     * @param array<array-key, mixed> $canvas
     *
     * @return array<string, string>
     */
    private static function objectNames(array $canvas): array
    {
        $names = [];
        foreach (is_array($canvas['objects'] ?? null) ? $canvas['objects'] : [] as $object) {
            if (!is_array($object)) {
                continue;
            }
            $id = $object['inputId'] ?? null;
            $name = $object['name'] ?? null;
            if (is_string($id) && $id !== '' && is_string($name) && trim($name) !== '' && !isset($names[$id])) {
                $names[$id] = $name;
            }
        }

        return $names;
    }
}
