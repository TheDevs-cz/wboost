<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * The seven entries of the editor's "Přidat tvar" picker — the PHP mirror of
 * `SHAPE_KINDS` in `assets/controllers/canvas_shapes.js`, and the vocabulary
 * the `shapeKind` custom property carries.
 *
 * It is deliberately a richer list than {@see CanvasShape::TYPES}: several
 * kinds compile to the SAME Fabric type (a čtverec and a čára are both a
 * `Rect`), and collapsing them would make a layers-panel row — and a
 * decompiled DSL element — unable to say what the designer actually drew.
 * Round-tripping keeps the kind, so `shape: "line"` in, `shape: "line"` out.
 */
enum CanvasShapeKind: string
{
    case Rectangle = 'rectangle';
    case Square = 'square';
    case Circle = 'circle';
    case Ellipse = 'ellipse';
    case Triangle = 'triangle';
    case Line = 'line';
    case Star = 'star';

    /**
     * The Fabric class name this kind is emitted as. Casing matches what
     * Fabric v7 serializes (`Rect`, not `rect`) — the readers all lower-case
     * before comparing, but writing the canonical form keeps a compiled canvas
     * indistinguishable from an editor save.
     */
    public function fabricType(): string
    {
        return match ($this) {
            self::Rectangle, self::Square, self::Line => 'Rect',
            self::Circle => 'Circle',
            self::Ellipse => 'Ellipse',
            self::Triangle => 'Triangle',
            self::Star => 'Polygon',
        };
    }

    /**
     * Does a corner radius mean anything here? Only for the `Rect`-backed
     * kinds. On an `Ellipse` the very same `rx`/`ry` keys ARE the radii — i.e.
     * the size — so accepting a corner radius there would silently resize the
     * shape rather than round it.
     */
    public function supportsCornerRadius(): bool
    {
        return $this->fabricType() === 'Rect';
    }

    /**
     * Best-effort recovery of the kind from a raw canvas object, for documents
     * that predate `shapeKind` or were authored elsewhere: fall back to the
     * geometric primitive the Fabric type implies.
     *
     * @param array<array-key, mixed> $object
     */
    public static function fromCanvasObject(array $object): null|self
    {
        $kind = $object['shapeKind'] ?? null;

        if (is_string($kind)) {
            $resolved = self::tryFrom($kind);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return match (strtolower(is_string($object['type'] ?? null) ? $object['type'] : '')) {
            'rect' => self::Rectangle,
            'circle' => self::Circle,
            'ellipse' => self::Ellipse,
            'triangle' => self::Triangle,
            'polygon', 'polyline' => self::Star,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
