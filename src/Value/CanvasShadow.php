<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * "Styl vrstvy" › Vržený stín / Vnější záře — Photoshop's controls over
 * Fabric's NATIVE `shadow` object (`{color, blur, offsetX, offsetY,
 * affectStroke, nonScaling}`). PHP twin of `assets/controllers/
 * canvas_layer_shadow.js`; the colour string `rgba(r,g,b,a)` with `a`
 * rounded to two decimals is byte-identical on both sides.
 *
 * A glow is a shadow at distance 0 — the kind is derived from the offsets,
 * nothing extra is stored. `angle` is the Photoshop LIGHT angle (120° =
 * shadow falls bottom-right); distance and size (= blur) are canvas px;
 * opacity is a 0…1 fraction (the DSL's convention).
 */
final readonly class CanvasShadow
{
    public const float MAX_PX = 2000.0;

    public function __construct(
        public CanvasShadowKind $kind,
        /** Lowercase `#rrggbb`. */
        public string $color,
        public float $opacity,
        public float $angle,
        public float $distance,
        public float $size,
    ) {
    }

    /**
     * The object's shadow, or null when it has none / it is unusable.
     *
     * @param array<array-key, mixed> $object a decoded canvas object
     */
    public static function fromObject(array $object): null|self
    {
        $shadow = $object['shadow'] ?? null;

        if (!is_array($shadow) || !is_string($shadow['color'] ?? null) || trim($shadow['color']) === '') {
            return null;
        }

        [$hex, $alpha] = self::parseColor($shadow['color']);
        $dx = self::number($shadow['offsetX'] ?? 0);
        $dy = self::number($shadow['offsetY'] ?? 0);
        $distance = round(hypot($dx, $dy), 1);

        return new self(
            kind: $distance > 0.0 ? CanvasShadowKind::DropShadow : CanvasShadowKind::OuterGlow,
            color: $hex,
            opacity: round($alpha, 2),
            angle: $distance > 0.0 ? round(rad2deg(atan2($dy, -$dx))) : 120.0,
            distance: $distance,
            size: round(max(0.0, self::number($shadow['blur'] ?? 0)), 1),
        );
    }

    /**
     * Fabric's `shadow` value.
     *
     * @return array{color: string, blur: float, offsetX: float, offsetY: float, affectStroke: false, nonScaling: true}
     */
    public function toFabric(): array
    {
        $distance = $this->kind === CanvasShadowKind::OuterGlow ? 0.0 : $this->distance;
        $radians = deg2rad($this->angle);

        return [
            'color' => self::rgba($this->color, $this->opacity),
            'blur' => $this->size,
            'offsetX' => round(-cos($radians) * $distance, 2) + 0.0,
            'offsetY' => round(sin($radians) * $distance, 2) + 0.0,
            'affectStroke' => false,
            'nonScaling' => true,
        ];
    }

    /**
     * A stored Fabric shadow with its px (blur, offsets) projected by a
     * ratio — the group projector's twin of `scaleShadow()` in JS.
     *
     * @param array<array-key, mixed> $shadow
     * @return array<array-key, mixed>
     */
    public static function scaleFabric(array $shadow, float $ratio): array
    {
        foreach (['blur', 'offsetX', 'offsetY'] as $key) {
            if (isset($shadow[$key]) && is_numeric($shadow[$key])) {
                $shadow[$key] = round((float) $shadow[$key] * $ratio, 2);
            }
        }

        return $shadow;
    }

    /**
     * @return array{kind: string, color: string, opacity: float, angle?: float, distance?: float, size: float}
     */
    public function toDsl(): array
    {
        $data = ['kind' => $this->kind->value, 'color' => $this->color, 'opacity' => $this->opacity];

        if ($this->kind === CanvasShadowKind::DropShadow) {
            $data['angle'] = $this->angle;
            $data['distance'] = $this->distance;
        }

        $data['size'] = $this->size;

        return $data;
    }

    /**
     * @return array{0: string, 1: float} lowercase `#rrggbb` + alpha 0…1
     */
    private static function parseColor(string $color): array
    {
        $value = strtolower(trim($color));

        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m) === 1) {
            return ['#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3], 1.0];
        }

        if (preg_match('/^#[0-9a-f]{6}$/', $value) === 1) {
            return [$value, 1.0];
        }

        if (preg_match('/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*(?:,\s*([\d.]+)\s*)?\)$/', $value, $m) === 1) {
            $hex = '#';
            foreach ([$m[1], $m[2], $m[3]] as $channel) {
                $hex .= sprintf('%02x', max(0, min(255, (int) round((float) $channel))));
            }

            $alpha = isset($m[4]) ? max(0.0, min(1.0, (float) $m[4])) : 1.0;

            return [$hex, $alpha];
        }

        return ['#000000', 1.0];
    }

    private static function rgba(string $hex, float $alpha): string
    {
        $n = (int) hexdec(ltrim($hex, '#'));
        $a = rtrim(rtrim(number_format(max(0.0, min(1.0, $alpha)), 2, '.', ''), '0'), '.');

        return sprintf('rgba(%d,%d,%d,%s)', ($n >> 16) & 255, ($n >> 8) & 255, $n & 255, $a === '' ? '0' : $a);
    }

    private static function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
