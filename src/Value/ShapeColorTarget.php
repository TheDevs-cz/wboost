<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * What a user's colour pick on a recolourable shape repaints — the shape's
 * `colorChoice` custom property ({@see ShapeColorInput}). One pick, one colour:
 * `Both` paints the fill AND the border in it.
 */
enum ShapeColorTarget: string
{
    case Fill = 'fill';
    case Stroke = 'stroke';
    case Both = 'both';

    public function paintsFill(): bool
    {
        return $this !== self::Stroke;
    }

    public function paintsStroke(): bool
    {
        return $this !== self::Fill;
    }
}
