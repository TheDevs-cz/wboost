<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print\Cmyk;

use WBoost\Web\Exceptions\CmykConversionFailed;
use WBoost\Web\Exceptions\PdfStructureNotSupported;

/**
 * A content stream with every RGB colour operator replaced by its CMYK
 * (or, inside a luminosity soft mask, DeviceGray) equivalent:
 *
 * | written | becomes |
 * |---|---|
 * | `r g b rg` / `RG` | `c m y k k` / `K` ({@see CmykColorRules}) |
 * | `/DeviceRGB cs` / `CS`, or a resource name of an RGB space (ICCBased N = 3, CalRGB) | `/DeviceCMYK cs` / `CS` |
 * | `r g b sc` / `scn` / `SC` / `SCN` while such a space is current | `c m y k scn` … |
 * | `g`, `G`, `k`, `K`, `/Pattern cs` + `/P1 scn`, `sh`, `Do`, `gs`, paths, text | untouched (what they name is converted where it is defined) |
 * | `BT` … `ET` while the fill colour is a nearly neutral RGB colour | the text is painted with black ink only ({@see CmykColorRules::textCmyk()}): the K-only colour is set in front of `BT` (or in place of an `rg` inside the text object) and the colour proper is set again behind `ET`, so what is FILLED next keeps its tint |
 *
 * The current colour space is tracked through `q` / `Q`. Everything is
 * checked against the list of PDF operators: an operator that is not one, an
 * inline image, an uncoloured pattern or a colour whose operand count does
 * not fit its space throws — an RGB colour must never slip through
 * unconverted.
 */
final class CmykContentRewriter
{
    /** Every operator of PDF 1.7 except the inline-image ones (refused by the lexer). */
    private const array OPERATORS = [
        'b', 'B', 'b*', 'B*', 'BDC', 'BMC', 'BT', 'BX', 'c', 'cm', 'CS', 'cs', 'd', 'd0', 'd1', 'Do', 'DP', 'EMC', 'ET', 'EX',
        'f', 'F', 'f*', 'G', 'g', 'gs', 'h', 'i', 'j', 'J', 'K', 'k', 'l', 'm', 'M', 'MP', 'n', 'q', 'Q', 're', 'RG', 'rg', 'ri',
        's', 'S', 'SC', 'sc', 'SCN', 'scn', 'sh', 'T*', 'Tc', 'Td', 'TD', 'Tf', 'Tj', 'TJ', 'TL', 'Tm', 'Tr', 'Ts', 'Tw', 'Tz',
        'v', 'w', 'W', 'W*', 'y', "'", '"',
    ];

    /**
     * @param array<string, string> $colorSpaces the names of the stream's `/ColorSpace` resources → their kind:
     *   `rgb`, `gray`, `cmyk`, `pattern` or `other` (indexed, separation — spaces whose operands need no change)
     * @param bool $mask the stream paints a luminosity soft mask: colours become DeviceGray
     *
     * @throws CmykConversionFailed
     */
    public static function rewrite(string $content, array $colorSpaces, CmykColorRules $rules, bool $mask): string
    {
        $operators = array_fill_keys(self::OPERATORS, true);
        // the graphics state that matters here — [fill space, stroke space, fill colour as RGB when it was set as one];
        // the initial colour space of a content stream is DeviceGray
        $state = ['gray', 'gray', null];
        $stack = [];
        // inside BT … ET; and the fill colour to set again behind ET when the text rule changed it
        $inText = false;
        $restore = null;

        /**
         * @return array{float, float, float}
         */
        $rgb = static function (array $operands, string $operator): array {
            $numbers = array_values(array_filter($operands, PdfSyntax::isNumber(...)));

            if (count($operands) !== 3 || count($numbers) !== 3) {
                throw new CmykConversionFailed(sprintf('An RGB colour operator "%s" does not have three numbers.', $operator));
            }

            return [
                is_string($numbers[0]) ? (float) $numbers[0] : 0.0,
                is_string($numbers[1]) ? (float) $numbers[1] : 0.0,
                is_string($numbers[2]) ? (float) $numbers[2] : 0.0,
            ];
        };

        $inks = static function (array $color, bool $text) use ($rules, $mask): string {
            [$r, $g, $b] = [
                is_float($color[0] ?? null) ? $color[0] : 0.0,
                is_float($color[1] ?? null) ? $color[1] : 0.0,
                is_float($color[2] ?? null) ? $color[2] : 0.0,
            ];

            if ($mask) {
                return PdfSyntax::number(CmykColorRules::luminosity($r, $g, $b));
            }

            return implode(' ', array_map(PdfSyntax::number(...), $text ? $rules->textCmyk($r, $g, $b) : $rules->cmyk($r, $g, $b)));
        };

        try {
            return PdfSyntax::operations($content, static function (string $operator, array $operands) use (&$state, &$stack, &$inText, &$restore, $operators, $colorSpaces, $rgb, $inks, $mask): null|string {
                if (!isset($operators[$operator])) {
                    throw new CmykConversionFailed(sprintf('Unknown content operator "%s".', substr($operator, 0, 20)));
                }

                switch ($operator) {
                    case 'q':
                        $stack[] = $state;

                        return null;

                    case 'Q':
                        $state = array_pop($stack) ?? $state;

                        return null;

                    case 'BT':
                        $inText = true;
                        $restore = null;

                        // glyphs take the fill colour that is current: a nearly neutral one prints in black only
                        if (!$mask && $state[2] !== null && $inks($state[2], true) !== $inks($state[2], false)) {
                            $restore = $inks($state[2], false) . ' k';

                            return $inks($state[2], true) . " k\nBT";
                        }

                        return null;

                    case 'ET':
                        $inText = false;
                        $after = $restore;
                        $restore = null;

                        // what is filled next must not inherit the text's black
                        return $after !== null ? "ET\n" . $after : null;

                    case 'rg':
                    case 'RG':
                        $index = $operator === 'rg' ? 0 : 1;
                        $color = $rgb($operands, $operator);
                        $state[$index] = $mask ? 'gray' : 'cmyk';

                        if ($index === 0) {
                            $state[2] = $color;
                            $restore = $inText && !$mask && $inks($color, true) !== $inks($color, false) ? $inks($color, false) . ' k' : null;
                        }

                        return $inks($color, $index === 0 && $inText) . ' ' . ($mask ? ['g', 'G'][$index] : ['k', 'K'][$index]);

                    case 'g':
                    case 'G':
                        $state[$operator === 'g' ? 0 : 1] = 'gray';

                        if ($operator === 'g') {
                            $state[2] = null;
                            $restore = null;
                        }

                        return null;

                    case 'k':
                    case 'K':
                        $state[$operator === 'k' ? 0 : 1] = 'cmyk';

                        if ($operator === 'k') {
                            $state[2] = null;
                            $restore = null;
                        }

                        return null;

                    case 'cs':
                    case 'CS':
                        $name = $operands[0] ?? '';

                        if (count($operands) !== 1 || !str_starts_with($name, '/')) {
                            throw new CmykConversionFailed(sprintf('The colour space operator "%s" has no name.', $operator));
                        }

                        $kind = match ($name) {
                            '/DeviceRGB' => 'rgb',
                            '/DeviceGray' => 'gray',
                            '/DeviceCMYK' => 'cmyk',
                            '/Pattern' => 'pattern',
                            default => $colorSpaces[substr($name, 1)] ?? null,
                        };

                        if ($kind === null) {
                            throw new CmykConversionFailed(sprintf('The colour space "%s" is not among the resources.', substr($name, 0, 40)));
                        }

                        $state[$operator === 'cs' ? 0 : 1] = $kind;

                        if ($operator === 'cs') {
                            $state[2] = null;
                            $restore = null;
                        }

                        // a named RGB space keeps its name: the resource itself now says DeviceCMYK
                        return $kind === 'rgb' && $name === '/DeviceRGB' ? ($mask ? '/DeviceGray ' : '/DeviceCMYK ') . $operator : null;

                    case 'sc':
                    case 'scn':
                    case 'SC':
                    case 'SCN':
                        $index = $operator === 'sc' || $operator === 'scn' ? 0 : 1;
                        $kind = $state[$index];
                        $numbers = count(array_filter($operands, PdfSyntax::isNumber(...)));

                        if ($kind === 'rgb') {
                            $color = $rgb($operands, $operator);

                            if ($index === 0) {
                                $state[2] = $color;
                                $restore = $inText && !$mask && $inks($color, true) !== $inks($color, false) ? $inks($color, false) . ' ' . $operator : null;
                            }

                            return $inks($color, $index === 0 && $inText) . ' ' . $operator;
                        }

                        if ($index === 0) {
                            $state[2] = null;
                            $restore = null;
                        }

                        if ($kind === 'pattern') {
                            if ($numbers !== 0 || count($operands) !== 1) {
                                throw new CmykConversionFailed('An uncoloured tiling pattern is painted with a colour; there is no rule for it.');
                            }

                            return null;
                        }

                        if (($kind === 'gray' && $numbers !== 1) || ($kind === 'cmyk' && $numbers !== 4) || count($operands) !== $numbers) {
                            throw new CmykConversionFailed(sprintf('The colour operator "%s" does not fit its colour space.', $operator));
                        }

                        return null;

                    default:
                        return null;
                }
            });
        } catch (PdfStructureNotSupported $unreadable) {
            throw new CmykConversionFailed($unreadable->getMessage(), 0, $unreadable);
        }
    }
}
