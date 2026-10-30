<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * What a document's shared field ("Společné pole") fills on every page it is
 * bound to: a plain text (no rich runs, no font choice — v1) or a picture
 * (shared pick, per-page placement).
 */
enum DocumentFieldKind: string
{
    case Text = 'text';
    case Image = 'image';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Image => 'Obrázek',
        };
    }
}
