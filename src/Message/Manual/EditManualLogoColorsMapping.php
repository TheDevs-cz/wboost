<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Manual;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\LogoColorVariant;
use WBoost\Web\Value\LogoTypeVariant;

readonly final class EditManualLogoColorsMapping
{
    public function __construct(
        public UuidInterface $manualId,
        public LogoTypeVariant $logoTypeVariant,
        public LogoColorVariant $logoColorVariant,
        public string $background,
        /** @var array<string, string> */
        public array $mapping,
    ) {
    }
}
