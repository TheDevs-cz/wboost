<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

/**
 * A fillable member of a repeatable row's TEMPLATE row — the key of a row
 * object in `rows`. `kind`: `text` (the id is also in `inputs[]`), `image`
 * (in `imageInputs[]`), `shape` (in `shapeInputs[]`) or `container` (a
 * hidable container of the row — the row itself included — taking
 * `{hide: true}` only).
 */
final readonly class TemplateVariantRepeatableRowMemberResponse
{
    public function __construct(
        public string $id,
        public string $kind,
        public null|string $name,
    ) {
    }
}
