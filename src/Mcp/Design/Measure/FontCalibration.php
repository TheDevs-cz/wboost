<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Measure;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Per-face width correction for {@see TextMeasurer}.
 *
 * `hmtx` gives the *unshaped* advance width of every glyph. Chromium measures
 * the same string with kerning (GPOS) and hinting applied, so the two can drift
 * by a fraction of a percent to a couple of percent on a given typeface — always
 * in the same direction for that typeface, which is exactly what a single
 * multiplier absorbs. Every measured advance is multiplied by the factor found
 * here, so `> 1.0` makes the estimator predict WIDER text (more wrapped lines).
 *
 * **The map is deliberately empty, and that was measured, not assumed.** Over a
 * 216-combination sweep on the fixture face (Nunito Regular; six Czech/English
 * strings × twelve box widths from 180 to 900 px × three font sizes) against
 * real Gotenberg renders, the uncalibrated estimator was **exact in 97.2 % of
 * cases, +1 line in 2.3 % and +2 lines in one** — the +2 being a 180 px box at a
 * 48 px font, where almost every word hard-breaks.
 *
 * Crucially the error is **one-sided: it never under-counts.** Kerning only ever
 * makes Chromium's text narrower than the sum of isolated advances, so the
 * estimate is a ceiling. That is the safe direction for an overflow warning —
 * early rather than missed. Tuning the factor down "improves" the exact-match
 * rate and destroys that property: 0.995 scores 212/216 exact but starts
 * UNDER-counting three of them. Optimise for the one-sidedness, not the hit rate.
 *
 * An entry here is therefore a claim backed by measurement, not a knob to twiddle.
 *
 * **How to derive one** (there is no command for this, on purpose — plan §7
 * leans towards committed constants):
 *
 *  1. Add the face to the Gotenberg probe in `TextMeasurerTest` (the
 *     `#[Group('gotenberg')]` test) with strings that wrap at several widths.
 *  2. Run `vendor/bin/phpunit --group gotenberg` and read Chromium's real
 *     `_textLines.length` out of the probe's marker.
 *  3. If the estimate is systematically one line SHORT across widths, the
 *     unshaped sum is too narrow → raise the factor; systematically one line
 *     LONG → lower it. Binary-search the smallest correction that fixes every
 *     fixture, then commit it here WITH the strings it was derived from.
 *
 * Keyed by the face's **PostScript name as read from the font file itself**
 * (`Nunito-Regular`, `Rubik-Bold`), never by the project-facing
 * `"Family (Face)"` wire string: the same typeface uploaded into ten projects
 * (under ten spellings) is one typeface, and its shaping behaviour does not
 * depend on what a designer called it.
 *
 * `#[Exclude]`: a constant table with static lookups, never a service.
 */
#[Exclude]
readonly final class FontCalibration
{
    /**
     * PostScript name => width multiplier.
     *
     * @var array<string, float>
     */
    public const array FACTORS = [];

    public const float DEFAULT_FACTOR = 1.0;

    public static function factorFor(null|string $postScriptName): float
    {
        if ($postScriptName === null) {
            return self::DEFAULT_FACTOR;
        }

        // Via a widened local: the constant is empty today, and reading it
        // directly makes static analysis prove the lookup can never hit.
        /** @var array<string, float> $factors */
        $factors = self::FACTORS;

        return $factors[$postScriptName] ?? self::DEFAULT_FACTOR;
    }
}
