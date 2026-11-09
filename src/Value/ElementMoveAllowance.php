<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * "Uživatel může posunout" — the designer's allowance for the END USER to move
 * an element (a text — locked ones too —, a shape, a decorative image, or a
 * TOP-LEVEL container as one block) on the fill page / via the API.
 *
 * Two independently toggled axes (↕ vertical, ↔ horizontal), each with its own
 * optional maximum PER DIRECTION (Jan's decision 2026-11-09: separate up /
 * down / left / right limits, not a symmetric ±). Maxima are canvas px of the
 * variant that stores them; `null` = unlimited, but the element stays on the
 * artwork (its bounding box may not leave the canvas — or, when the design
 * already hangs it over an edge, may not go further out than designed).
 * `0` in a direction = "not this way" (e.g. `maxDown: 0` = only upwards).
 *
 * Canvas JSON contract (custom property `userMove` on the Fabric object, or a
 * `userMove` key on a `containers[]` definition), emitted ONLY when at least
 * one axis is allowed, always in this canonical shape:
 *
 *     "userMove": {"vertical": true, "horizontal": false,
 *                  "maxUp": 120, "maxDown": null, "maxLeft": null, "maxRight": null}
 *
 * A disabled axis always carries null maxima. Values are rounded to 1/100 px.
 *
 * The JS twin is `assets/editor/element_move.js` (`window.WBoostElementMove`):
 * normalize / scale / bounds / clamp / bake must agree byte-for-byte —
 * pinned by `ElementMoveParityTest` (node harness).
 *
 * No DB column: like {@see ShapeColorInput} the allowance is read from the
 * canvas on demand.
 */
readonly final class ElementMoveAllowance
{
    public const string CANVAS_KEY = 'userMove';

    public function __construct(
        public bool $vertical,
        public bool $horizontal,
        public null|float $maxUp = null,
        public null|float $maxDown = null,
        public null|float $maxLeft = null,
        public null|float $maxRight = null,
    ) {
    }

    /**
     * Lenient normalisation of a stored / submitted value. Anything that does
     * not allow at least one axis yields null (= not movable). A maximum that
     * is not a finite non-negative number reads as null (unlimited); maxima
     * of a disabled axis are dropped.
     */
    public static function fromArray(mixed $data): null|self
    {
        if (!is_array($data)) {
            return null;
        }

        $vertical = ($data['vertical'] ?? false) === true;
        $horizontal = ($data['horizontal'] ?? false) === true;

        if (!$vertical && !$horizontal) {
            return null;
        }

        return new self(
            $vertical,
            $horizontal,
            $vertical ? self::maxOrNull($data['maxUp'] ?? null) : null,
            $vertical ? self::maxOrNull($data['maxDown'] ?? null) : null,
            $horizontal ? self::maxOrNull($data['maxLeft'] ?? null) : null,
            $horizontal ? self::maxOrNull($data['maxRight'] ?? null) : null,
        );
    }

    /**
     * @param array<array-key, mixed> $object a Fabric object or a `containers[]` definition
     */
    public static function fromCanvasObject(array $object): null|self
    {
        return self::fromArray($object[self::CANVAS_KEY] ?? null);
    }

    /**
     * @return array{vertical: bool, horizontal: bool, maxUp: null|float, maxDown: null|float, maxLeft: null|float, maxRight: null|float}
     */
    public function toArray(): array
    {
        return [
            'vertical' => $this->vertical,
            'horizontal' => $this->horizontal,
            'maxUp' => $this->maxUp,
            'maxDown' => $this->maxDown,
            'maxLeft' => $this->maxLeft,
            'maxRight' => $this->maxRight,
        ];
    }

    /**
     * The same allowance in another dimension of a template group: vertical
     * maxima scale by the HEIGHT ratio, horizontal by the WIDTH ratio (the
     * "1 % left" contract of group_projection.js) — "40 px down" on 1080×1350
     * is the same visual share as 57 px on 1080×1920. Unlimited stays
     * unlimited.
     */
    public function scaled(float $rx, float $ry): self
    {
        return new self(
            $this->vertical,
            $this->horizontal,
            self::scaleMax($this->maxUp, $ry),
            self::scaleMax($this->maxDown, $ry),
            self::scaleMax($this->maxLeft, $rx),
            self::scaleMax($this->maxRight, $rx),
        );
    }

    /**
     * The offset range (canvas px, relative to the designed position) the
     * element may be moved within. `minX`/`minY` ≤ 0 ≤ `maxX`/`maxY` always:
     * the designed position is always allowed. An unlimited direction stops
     * where the element's bounding box would leave the artwork (0 when the
     * design already hangs it over that edge).
     *
     * @param array{left: float, top: float, width: float, height: float} $frame the element's designed axis-aligned bounding box
     *
     * @return array{minX: float, maxX: float, minY: float, maxY: float}
     */
    public function bounds(array $frame, float $canvasWidth, float $canvasHeight): array
    {
        $minX = 0.0;
        $maxX = 0.0;
        $minY = 0.0;
        $maxY = 0.0;

        if ($this->horizontal) {
            $minX = -($this->maxLeft ?? max(0.0, $frame['left']));
            $maxX = $this->maxRight ?? max(0.0, $canvasWidth - ($frame['left'] + $frame['width']));
        }

        if ($this->vertical) {
            $minY = -($this->maxUp ?? max(0.0, $frame['top']));
            $maxY = $this->maxDown ?? max(0.0, $canvasHeight - ($frame['top'] + $frame['height']));
        }

        return [
            'minX' => self::round2($minX),
            'maxX' => self::round2($maxX),
            'minY' => self::round2($minY),
            'maxY' => self::round2($maxY),
        ];
    }

    /**
     * Clamp a requested offset into {@see self::bounds()}: a disallowed axis
     * becomes 0, a non-finite value 0, everything rounded to 1/100 px.
     *
     * @param array{left: float, top: float, width: float, height: float} $frame
     *
     * @return array{x: float, y: float}
     */
    public function clamp(float $x, float $y, array $frame, float $canvasWidth, float $canvasHeight): array
    {
        $bounds = $this->bounds($frame, $canvasWidth, $canvasHeight);

        $x = is_finite($x) ? $x : 0.0;
        $y = is_finite($y) ? $y : 0.0;

        return [
            'x' => self::round2(min(max($x, $bounds['minX']), $bounds['maxX'])),
            'y' => self::round2(min(max($y, $bounds['minY']), $bounds['maxY'])),
        ];
    }

    /**
     * Half-away-from-zero rounding to 1/100 written out with plain IEEE ops,
     * so PHP and JS (`Math.floor`) produce the identical double — PHP's own
     * round() pre-rounding differs from Math.round on negatives / .5 edges.
     * Never returns -0.0.
     */
    public static function round2(float $value): float
    {
        if (!is_finite($value)) {
            return 0.0;
        }

        $rounded = floor(abs($value) * 100 + 0.5) / 100;

        if ($rounded == 0.0) {
            return 0.0;
        }

        return $value < 0 ? -$rounded : $rounded;
    }

    private static function maxOrNull(mixed $value): null|float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }

        $value = (float) $value;

        return (!is_finite($value) || $value < 0.0) ? null : self::round2($value);
    }

    private static function scaleMax(null|float $value, float $ratio): null|float
    {
        return $value === null ? null : self::round2($value * $ratio);
    }
}
