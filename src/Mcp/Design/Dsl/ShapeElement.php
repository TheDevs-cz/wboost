<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

use WBoost\Web\Value\CanvasShapeGradient;
use WBoost\Web\Value\CanvasShapeKind;
use WBoost\Web\Value\CanvasShapeStroke;

/**
 * A vector shape — the DSL face of the editor's "Přidat tvar" primitive
 * (rectangle / square / circle / ellipse / triangle / line / star).
 *
 * Shapes are **decorative by definition**: they carry no input spec, never
 * reach `inputs[]` / `imageInputs[]`, and add nothing to the fill or export
 * API. What they do carry is an `inputId` like every other drawable element,
 * because that is the join key group sync propagates on and containers address
 * members by — which is also why a shape MAY be a container member (a rule
 * under a heading rides along with it) while a fillable image placeholder may
 * not.
 *
 * {@see $shape} is the KIND, not the Fabric class: a čtverec and a čára are
 * both a `Rect`, and collapsing them would lose what the designer drew. The
 * compiler maps kind → Fabric type via {@see CanvasShapeKind::fabricType()}
 * and the decompiler reads `shapeKind` straight back, so the round-trip is
 * lossless.
 *
 * {@see $fill} is either a lowercase `#rrggbb` string or a two-stop
 * {@see CanvasShapeGradient}. Unlike {@see TextElement::$color}, the parser
 * does NOT check it against the project's brand palette: the editor's own
 * swatches are suggestions and its picker accepts any colour, so a DSL that
 * refused one would be stricter than the UI it is supposed to describe.
 * Off-brand colours are the linter's business.
 *
 * There is no `angle` key: the editor can rotate a shape, but the DSL has no
 * rotation vocabulary for ANY element yet, and adding it for shapes alone
 * would be a grammar most agents would then try on texts and images too.
 */
readonly final class ShapeElement implements DesignElement
{
    public const string DEFAULT_FILL = '#000000';

    public function __construct(
        public string $id,
        public CanvasShapeKind $shape,
        /** Lowercase `#rrggbb`, or a two-stop gradient. */
        public string|CanvasShapeGradient $fill,
        /** Border colour as lowercase `#rrggbb`, or null for no border. */
        public null|string $stroke,
        /** Border weight in canvas px, >= 0. Zero paints nothing. */
        public float $strokeWidth,
        public CanvasShapeStroke $strokeStyle,
        /**
         * Corner rounding in canvas px, >= 0. Only meaningful for the
         * `Rect`-backed kinds ({@see CanvasShapeKind::supportsCornerRadius()});
         * elsewhere it must stay 0, since Fabric's `rx`/`ry` are the RADII of
         * an ellipse and rounding one would resize it.
         */
        public float $cornerRadius,
        /** 0…1. */
        public float $opacity,
        /** Layers-panel label. Purely cosmetic — a shape is not an input. */
        public null|string $name,
        /** The EDITOR lock (`editorLocked`): click-through and immovable on
         *  the canvas. Never reaches the render. */
        public bool $locked,
        public Placement $placement,
    ) {
    }

    public function kind(): ElementKind
    {
        return ElementKind::Shape;
    }

    /**
     * The shape's box. Unlike a textbox — whose height Fabric computes from
     * the wrapped text — a shape is whatever size it is authored at, so an
     * unauthored height falls back to a square. That mirrors
     * {@see \WBoost\Web\Mcp\Design\DesignCompiler::imageFrameHeight()}'s
     * no-asset case: the honest default when nothing implies a proportion.
     */
    public static function frameHeight(Rect $rect): float
    {
        return $rect->height ?? $rect->width;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $placement = $this->placement->toArray();

        return [
            'kind' => ElementKind::Shape->value,
            'id' => $this->id,
            'shape' => $this->shape->value,
            'fill' => $this->fill instanceof CanvasShapeGradient ? $this->fill->toArray() : $this->fill,
            'stroke' => $this->stroke,
            'strokeWidth' => $this->strokeWidth,
            'strokeStyle' => $this->strokeStyle->value,
            'cornerRadius' => $this->cornerRadius,
            'opacity' => $this->opacity,
            'name' => $this->name,
            'locked' => $this->locked,
            'at' => $placement['at'],
            'x' => $placement['x'],
            'y' => $placement['y'],
            'width' => $placement['width'],
            'height' => $placement['height'],
        ];
    }
}
