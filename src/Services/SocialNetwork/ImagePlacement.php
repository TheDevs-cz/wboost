<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use WBoost\Web\Value\PlaceholderFrame;

/**
 * Pure placement math, run identically on the server (final render) and the
 * client (live fill preview): given a placeholder {@see PlaceholderFrame}, the
 * chosen image's natural size, and the frame-relative transform
 * (`scale`/`offsetX`/`offsetY`/`rotation`), produce the absolute Fabric object
 * properties for a centre-origin image plus a frame-sized `clipPath` so the
 * picture stays inside the designer's window.
 *
 * Because both sides feed the same natural size + frame, the results match
 * pixel-for-pixel regardless of the on-screen zoom of the editing canvas.
 */
readonly final class ImagePlacement
{
    /**
     * @return array<string, mixed> Fabric object properties to merge onto the placeholder.
     */
    public function compute(
        PlaceholderFrame $frame,
        int $imageWidth,
        int $imageHeight,
        float $scale,
        float $offsetX,
        float $offsetY,
        float $rotation,
    ): array {
        $imageWidth = $imageWidth > 0 ? $imageWidth : 1;
        $imageHeight = $imageHeight > 0 ? $imageHeight : 1;

        $containScale = min($frame->width / $imageWidth, $frame->height / $imageHeight);
        if ($containScale <= 0.0) {
            $containScale = 1.0;
        }

        $finalScale = $containScale * $scale;
        $centerX = $frame->centerX() + $offsetX;
        $centerY = $frame->centerY() + $offsetY;

        return [
            'left' => $centerX,
            'top' => $centerY,
            'originX' => 'center',
            'originY' => 'center',
            'scaleX' => $finalScale,
            'scaleY' => $finalScale,
            'angle' => $rotation,
            'width' => $imageWidth,
            'height' => $imageHeight,
            // Reset any transform the designer applied to the stand-in so only
            // the computed placement governs the final picture.
            'flipX' => false,
            'flipY' => false,
            'skewX' => 0,
            'skewY' => 0,
            // Absolute-positioned clip rect == the frame, in canvas coords, so
            // panned / zoomed / rotated content never spills outside the window.
            'clipPath' => [
                'type' => 'Rect',
                'originX' => 'center',
                'originY' => 'center',
                'left' => $frame->centerX(),
                'top' => $frame->centerY(),
                'width' => $frame->width,
                'height' => $frame->height,
                'scaleX' => 1,
                'scaleY' => 1,
                'angle' => 0,
                'absolutePositioned' => true,
            ],
        ];
    }
}
