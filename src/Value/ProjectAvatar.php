<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * Visual identity of a project card: the brand logo when a manual has one,
 * otherwise a monogram circle (initials on a colored background).
 */
readonly final class ProjectAvatar
{
    /**
     * Fallback monogram backgrounds. All hues are saturated enough that the
     * YIQ contrast check picks white text on every one of them.
     */
    private const array PALETTE = [
        '3b76ef',
        '6c5ce7',
        '0aa2af',
        '2f9e44',
        'e8590c',
        'd6336c',
        '9c36b5',
        'c92a2a',
        '1971c2',
        '5f3dc4',
    ];

    private function __construct(
        public null|string $logoPath,
        public string $initials,
        /** Hex without '#'. */
        public string $backgroundHex,
        /** Hex without '#'. */
        public string $textHex,
    ) {
    }

    public static function build(
        string $seed,
        string $projectName,
        null|string $logoPath,
        null|string $brandColorHex,
    ): self {
        $backgroundHex = ltrim($brandColorHex ?? self::paletteColor($seed), '#');

        return new self(
            logoPath: $logoPath,
            initials: self::initials($projectName),
            backgroundHex: $backgroundHex,
            textHex: self::textColorFor($backgroundHex),
        );
    }

    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '?';
        }

        $initials = mb_substr($words[0], 0, 1);

        if (count($words) > 1) {
            $initials .= mb_substr($words[1], 0, 1);
        }

        return mb_strtoupper($initials);
    }

    /**
     * Deterministic palette pick — the same seed (project id) always gets the
     * same color, so a project's monogram never changes between page loads.
     */
    public static function paletteColor(string $seed): string
    {
        return self::PALETTE[crc32($seed) % count(self::PALETTE)];
    }

    /**
     * White text on dark backgrounds, dark text on light ones (YIQ formula).
     */
    public static function textColorFor(string $backgroundHex): string
    {
        $hex = ltrim($backgroundHex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $red = (int) hexdec(substr($hex, 0, 2));
        $green = (int) hexdec(substr($hex, 2, 2));
        $blue = (int) hexdec(substr($hex, 4, 2));

        $yiq = ($red * 299 + $green * 587 + $blue * 114) / 1000;

        return $yiq >= 150 ? '313a46' : 'ffffff';
    }
}
