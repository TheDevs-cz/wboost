<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * What a document's shared field ("Společné pole") fills on every page it is
 * bound to: a text input of any kind (plain, rich, list, checklist — the
 * field offers what every binding accepts, {@see DocumentTextMode}), a
 * picture (shared pick, per-page placement) or a recolourable shape's colour.
 */
enum DocumentFieldKind: string
{
    case Text = 'text';
    case Image = 'image';
    case Shape = 'shape';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Image => 'Obrázek',
            self::Shape => 'Barva tvaru',
        };
    }
}
