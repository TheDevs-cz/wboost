<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * The Fabric object types the editor's "Přidat tvar" picker produces — the PHP
 * single source of truth mirroring `SHAPE_FABRIC_TYPES` in
 * `assets/controllers/canvas_shapes.js` (and its dependency-free copy in
 * `assets/editor/container_layout.js`, which must stay a classic script).
 *
 * Shapes are DECORATIVE: they carry no fillable-input metadata, never reach the
 * `inputs` / `imageInputs` DTOs and add nothing to the API contract. Nothing
 * server-side has to know how to DRAW them either — `canvas.loadFromJSON`
 * enlivens every built-in Fabric type, so the headless export renders them for
 * free. The handful of places that do care are the ones that reason about an
 * object's ROLE rather than its pixels: container membership and cross-
 * dimension stroke projection.
 */
final class CanvasShape
{
    /**
     * Lower-cased Fabric type names. `line` and `path` are not produced by the
     * picker today (a divider is authored as a thin rectangle, which scales and
     * snaps like every other shape), but they are recognised so a canvas that
     * acquires one — an import, a hand-edited document — is classified the same
     * way rather than falling through to the image branch.
     *
     * @var list<string>
     */
    public const array TYPES = [
        'rect', 'circle', 'ellipse', 'triangle', 'polygon', 'polyline', 'line', 'path',
    ];

    /**
     * @param string $type raw `type` value off a canvas object (any casing —
     *        Fabric v5 emitted 'rect', v7 emits 'Rect')
     */
    public static function isShapeType(null|string $type): bool
    {
        return $type !== null && in_array(strtolower($type), self::TYPES, true);
    }
}
