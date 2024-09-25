<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Font;

use Ramsey\Uuid\UuidInterface;

readonly final class SortFontFaces
{
    public function __construct(
        public UuidInterface $fontId,
        /** @var array<string> */
        public array $fontFaces,
    ) {
    }
}
