<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * What kind of thing the DSL could not carry out of an existing canvas.
 *
 * Separate from {@see CompileErrorCode} and {@see \WBoost\Web\Mcp\Design\Dsl\DslErrorCode}
 * because it answers a different question. Those two say *"your document is
 * wrong"*; these say *"this design is older/richer than the DSL, and here is
 * exactly what you would destroy by writing it back"*. Nothing here is an
 * error: the decompilation succeeded, the agent just has to be told the truth
 * about it (see {@see DesignDecompiler} on why lossy-and-loud beats refusing).
 *
 * `#[Exclude]`: a value, never a service.
 */
#[Exclude]
enum DesignLossCode: string
{
    /**
     * A whole canvas object has no DSL representation and is simply not in the
     * document — a Rect / Path / Group, a design-hidden layer, an `IText`.
     * The most destructive code: writing the document back DELETES it.
     */
    case ObjectDropped = 'object_dropped';

    /**
     * The object survives but its stack position changes on the way back —
     * only ever the background layer, which the compiler pins to index 0
     * (plan §4.3 invariant 11) wherever the stored canvas keeps it.
     */
    case ObjectRestacked = 'object_restacked';

    /**
     * Geometry the DSL's axis-aligned rect cannot express: rotation, flips,
     * skew, image cropping, a non-uniformly scaled decorative image, a
     * background layer that is not the canonical cover fit. The object
     * survives; it moves or changes shape.
     */
    case TransformDropped = 'transform_dropped';

    /**
     * Painting the DSL has no words for: per-character styles, gradients and
     * patterns, shadows, strokes, opacity, text decoration, letter spacing,
     * image filters, the editor's lock flag.
     */
    case StyleDropped = 'style_dropped';

    /**
     * Per-input machinery beyond the seven keys of DSL v1: lists, checkbox
     * lists, the checklist component and their styling, plus the input
     * `description`. Stage-6+ DSL surface (plan §3.4).
     */
    case InputFeatureDropped = 'input_feature_dropped';

    /**
     * A document-level feature: ruler guides, a canvas-level `backgroundImage`
     * (legacy {@see \WBoost\Web\Value\BackgroundMode::Canvas} variants), a
     * canvas overlay or clip path.
     */
    case CanvasFeatureDropped = 'canvas_feature_dropped';

    /**
     * A picture whose storage path is not a gallery image, so the DSL — which
     * addresses pictures by gallery id only — cannot name it. Writing the
     * document back leaves the object in place with NO picture.
     *
     * This is not hypothetical: backgrounds uploaded through the add/edit
     * variant form are stored under `custom-templates/{variantId}/…` and have
     * no `file_upload` row at all.
     */
    case AssetUnresolved = 'asset_unresolved';

    /**
     * The element's `inputId` cannot be preserved — two objects claim the same
     * one, and {@see DesignCompiler} refuses to hand one UUID to two slugs
     * (duplicate ids break the first-match lookups in the renderer and the
     * frame binder). The second claimant gets a fresh id, so saved fills and
     * API consumers keyed on it stop resolving for that element.
     */
    case IdentityRemapped = 'identity_remapped';
}
