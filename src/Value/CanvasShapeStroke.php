<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A shape's border style — the PHP mirror of `_applyDashStyle` / `dashStyleOf`
 * in `assets/controllers/canvas_shape_properties_controller.js`.
 *
 * The style is NOT stored: it is derived from `strokeDashArray` on read and
 * recomputed from the CURRENT stroke width on write. That is what keeps a
 * dashed border reading as dashed at any weight — a fixed `[6, 4]` on a 20 px
 * outline is a solid line with nicks in it — and it saves a custom property
 * that could drift out of step with the array it describes.
 */
enum CanvasShapeStroke: string
{
    case Solid = 'solid';
    case Dashed = 'dashed';
    case Dotted = 'dotted';

    /**
     * Fabric's `strokeDashArray` for this style at the given stroke width, or
     * null for a solid line.
     *
     * @return null|list<float>
     */
    public function dashArray(float $strokeWidth): null|array
    {
        $width = max(1.0, $strokeWidth);

        return match ($this) {
            self::Solid => null,
            self::Dashed => [$width * 3, $width * 2],
            // Zero-length dashes with round caps = true dots, at any weight.
            self::Dotted => [0.0, $width * 2],
        };
    }

    /**
     * The dash array for a shape of the given kind and displayed size.
     *
     * A `line` is a filled bar, and its style is the style of the LINE: the
     * render patch (`assets/editor/fabric_line_style.js`) draws a styled line's
     * body as dots / dashes as thick as the bar and does not trace its border.
     * Its pattern is therefore derived from that thickness (the bar's short
     * side), with a two-dot gap for dots — the array is descriptive (the
     * patch reads only the style off it and fits the pattern to the length),
     * but it is what an editor save writes, so compiled and authored canvases
     * stay identical. Every other kind dashes its BORDER, by stroke width.
     *
     * @return null|list<float>
     */
    public function dashArrayFor(CanvasShapeKind $kind, float $strokeWidth, float $width, float $height): null|array
    {
        if ($kind !== CanvasShapeKind::Line) {
            return $this->dashArray($strokeWidth);
        }

        $thickness = max(1.0, min($width, $height));

        return match ($this) {
            self::Solid => null,
            self::Dashed => [$thickness * 3, $thickness * 2],
            self::Dotted => [0.0, $thickness * 3],
        };
    }

    public function lineCap(): string
    {
        return $this === self::Dotted ? 'round' : 'butt';
    }

    /**
     * Which style a stored dash array represents.
     *
     * @param mixed $dashArray raw `strokeDashArray` off a canvas object
     */
    public static function fromDashArray(mixed $dashArray): self
    {
        if (!is_array($dashArray) || $dashArray === []) {
            return self::Solid;
        }

        $first = reset($dashArray);

        return is_numeric($first) && (float) $first === 0.0 ? self::Dotted : self::Dashed;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
