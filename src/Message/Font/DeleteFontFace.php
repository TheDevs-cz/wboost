<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Font;

use Ramsey\Uuid\UuidInterface;

readonly final class DeleteFontFace
{
    public function __construct(
        public UuidInterface $fontId,
        public string $fontFaceName,
    ) {
    }
}
