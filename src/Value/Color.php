<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use Stringable;
use WBoost\Web\Exceptions\InvalidColorHex;

readonly final class Color implements Stringable
{
    public string $hex;

    /** @var int[]  */
    public array $rgb;

    /**
     * @throws InvalidColorHex
     */
    public function __construct(
        string $hex,
    ) {
        if (!self::isValidHex($hex)) {
            throw new InvalidColorHex();
        }

        $this->hex = ltrim($hex, '#');
        $this->rgb = $this->hexToRgb($this->hex);

    }

    public static function isValidHex(string $hex): bool
    {
        if (!preg_match('/^#?[0-9A-Fa-f]{3,6}$/', $hex)) {
            return false;
        }

        return true;
    }


    /**
     * @return array<int>
     */
    private function hexToRgb(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2))
        ];
    }

    public function isWhite(): bool
    {
        $hex = strtolower($this->hex);

        return $hex === 'fff' || $hex === 'ffffff';
    }

    public function __toString(): string
    {
        return $this->hex;
    }
}
