<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * Process colour in ink percentages (0–100 per channel), as a brand manual
 * specifies it for print.
 */
readonly final class CmykColor
{
    /**
     * How far (Euclidean, 0–255 RGB space) a manual's CMYK may sit from the
     * colour's own hex before it is treated as a data-entry mistake. The
     * naive CMYK→RGB conversion it is measured with is crude, so the bound is
     * generous — it only has to catch the "000000 stored as 0/0/0/0" kind
     * of entry (distance ≈ 441), not judge colour management.
     */
    private const float PLAUSIBLE_RGB_DISTANCE = 150.0;

    public function __construct(
        public float $c,
        public float $m,
        public float $y,
        public float $k,
    ) {
    }

    /**
     * The manual's free-text CMYK fields, read as leniently as is safe:
     * "100", " 100 % " and "35,5" are all accepted, a blank
     * channel next to filled ones counts as 0 (the designer left the zero
     * out), and anything else — letters, out-of-range values, a CMYK that
     * cannot be the colour its hex says — yields null, so the caller falls
     * back to ICC conversion instead of printing a wrong number.
     */
    public static function fromManualColor(ManualColor $manualColor): null|self
    {
        $channels = $manualColor->cmyk;

        if ($channels === null) {
            return null;
        }

        $values = [];
        $anyFilled = false;

        foreach ($channels as $raw) {
            if ($raw === null || trim($raw) === '') {
                $values[] = 0.0;
                continue;
            }

            $parsed = self::parseChannel($raw);

            if ($parsed === null) {
                return null;
            }

            $values[] = $parsed;
            $anyFilled = true;
        }

        if (!$anyFilled) {
            return null;
        }

        $cmyk = new self($values[0], $values[1], $values[2], $values[3]);

        return $cmyk->isPlausibleFor($manualColor->color) ? $cmyk : null;
    }

    private static function parseChannel(string $raw): null|float
    {
        $normalized = str_replace([',', '%', ' ', "\u{00A0}"], ['.', '', '', ''], trim($raw));

        if (preg_match('/^\d+(\.\d+)?$/', $normalized) !== 1) {
            return null;
        }

        $value = (float) $normalized;

        return $value <= 100.0 ? $value : null;
    }

    /**
     * Black ink only — what the print pipeline overprints for text and hairlines.
     */
    public function isBlackOnly(): bool
    {
        return $this->k >= 99.5 && $this->c <= 0.5 && $this->m <= 0.5 && $this->y <= 0.5;
    }

    public function isPlausibleFor(Color $color): bool
    {
        $hex = RichText::normalizeHexColor($color->hex);

        if ($hex === null) {
            return false;
        }

        $expected = [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];

        $distance = 0.0;
        foreach ($this->naiveRgb() as $index => $channel) {
            $distance += ($channel - $expected[$index]) ** 2;
        }

        return sqrt($distance) <= self::PLAUSIBLE_RGB_DISTANCE;
    }

    /**
     * @return array{float, float, float}
     */
    private function naiveRgb(): array
    {
        $k = 1 - $this->k / 100;

        return [
            255 * (1 - $this->c / 100) * $k,
            255 * (1 - $this->m / 100) * $k,
            255 * (1 - $this->y / 100) * $k,
        ];
    }
}
