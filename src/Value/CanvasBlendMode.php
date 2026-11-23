<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * Photoshop's "Režim prolnutí" as a 2D canvas draws it — the values of
 * Fabric's native `globalCompositeOperation`, the carrier on the canvas
 * object (no custom property).
 *
 * The 16 modes a canvas draws natively, in Photoshop's order; the other 11
 * PS modes have no canvas operator and are never offered (Jan, 2026-11-21).
 * Mirrors `BLEND_MODE_GROUPS` in `assets/controllers/canvas_blend_modes.js`
 * — `CanvasBlendModeTest` parses that file to keep the two in step.
 */
enum CanvasBlendMode: string
{
    case Normal = 'source-over';
    case Darken = 'darken';
    case Multiply = 'multiply';
    case ColorBurn = 'color-burn';
    case Lighten = 'lighten';
    case Screen = 'screen';
    case ColorDodge = 'color-dodge';
    case Overlay = 'overlay';
    case SoftLight = 'soft-light';
    case HardLight = 'hard-light';
    case Difference = 'difference';
    case Exclusion = 'exclusion';
    case Hue = 'hue';
    case Saturation = 'saturation';
    case Color = 'color';
    case Luminosity = 'luminosity';

    /**
     * The object's blend mode; absent / unknown reads as Normal (Fabric's
     * default, and what an unknown operator would degrade to anyway).
     *
     * @param array<array-key, mixed> $object a decoded canvas object
     */
    public static function fromObject(array $object): self
    {
        $value = $object['globalCompositeOperation'] ?? null;

        return is_string($value) ? (self::tryFrom($value) ?? self::Normal) : self::Normal;
    }

    /**
     * Whether the object blends with what lies under it (any mode but
     * Normal) — the case a flat, separately composited layer (an overlay
     * slice, the echo canvas, a print ink pass) cannot reproduce.
     *
     * @param array<array-key, mixed> $object
     */
    public static function blends(array $object): bool
    {
        return self::fromObject($object) !== self::Normal;
    }

    /**
     * The design-DSL word: the CSS `mix-blend-mode` name (`normal` for
     * source-over), the same vocabulary the calendar's effects use.
     */
    public function dslName(): string
    {
        return $this === self::Normal ? 'normal' : $this->value;
    }

    public static function fromDslName(string $name): null|self
    {
        if ($name === 'normal') {
            return self::Normal;
        }

        $mode = self::tryFrom($name);

        // `source-over` is the canvas word for normal, not a DSL one.
        return $mode === self::Normal ? null : $mode;
    }

    /**
     * @return list<string>
     */
    public static function dslNames(): array
    {
        return array_map(static fn (self $mode): string => $mode->dslName(), self::cases());
    }
}
