<?php

declare(strict_types=1);

namespace WBoost\Web\Value\ManualContent;

/**
 * - natural: the picture at its own aspect ratio, never wider than the
 *   segment (centred) — exactly how the legacy intro image rendered.
 * - cover: fills the segment's width cropped to the slot's canonical
 *   aspect ratio (the mockup-page look), opt-in per image.
 */
enum ContentImageFit: string
{
    case Natural = 'natural';
    case Cover = 'cover';
}
