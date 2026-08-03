<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * How a template variant stores its background.
 *
 * Canvas: the legacy style — the background is Fabric's canvas-level
 * `backgroundImage`, mirrored from the variant's `background_image` column and
 * re-cover-fitted (center-anchored) on every load. All variants created before
 * the background-as-layer rework keep this style forever.
 *
 * Layer: the background is a regular image object in the canvas document's
 * `objects[]`, marked with the custom prop `isBackground: true`, initially
 * placed cover-fit anchored top-left (overflow crops bottom-right). It is
 * optional — a layer-mode variant without a background renders transparent.
 * The `background_image` column stays maintained as a denormalized pointer to
 * the layer's asset (for thumbnails + API), null when there is no background.
 */
enum BackgroundMode: string
{
    case Canvas = 'canvas';
    case Layer = 'layer';
}
