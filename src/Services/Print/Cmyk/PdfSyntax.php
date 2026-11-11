<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print\Cmyk;

use WBoost\Web\Exceptions\PdfStructureNotSupported;

/**
 * PDF object syntax, both ways: bytes → a value tree ({@see PdfDict}, lists,
 * {@see PdfName}, {@see PdfRef}, {@see PdfString}, and PHP strings for
 * numbers / `true` / `false` / `null` kept exactly as written) and back.
 * Plus the lexer of content streams ({@see operations()}).
 *
 * Numbers stay text on purpose: a font matrix or a path coordinate that is
 * only copied must come out digit for digit.
 */
final class PdfSyntax
{
    private const string WHITESPACE = "\x00\t\n\x0C\r ";

    private const string DELIMITERS = "()<>[]{}/%";

    /**
     * The value that starts at `$at` (leading whitespace and comments are
     * skipped); `$at` is left right behind it.
     *
     * @throws PdfStructureNotSupported
     */
    public static function parse(string $bytes, int &$at, int $depth = 0): mixed
    {
        if ($depth > 64) {
            throw new PdfStructureNotSupported('A PDF value is nested too deep.');
        }

        self::skipSpace($bytes, $at);
        $char = $bytes[$at] ?? '';

        if ($char === '') {
            throw new PdfStructureNotSupported('A PDF value ends before it is complete.');
        }

        if ($char === '<' && ($bytes[$at + 1] ?? '') === '<') {
            $at += 2;
            $entries = [];

            while (true) {
                self::skipSpace($bytes, $at);

                if (($bytes[$at] ?? '') === '>' && ($bytes[$at + 1] ?? '') === '>') {
                    $at += 2;

                    return new PdfDict($entries);
                }

                $key = self::parse($bytes, $at, $depth + 1);

                if (!$key instanceof PdfName) {
                    throw new PdfStructureNotSupported('A PDF dictionary key is not a name.');
                }

                $entries[$key->value] = self::parse($bytes, $at, $depth + 1);
            }
        }

        if ($char === '[') {
            ++$at;
            $items = [];

            while (true) {
                self::skipSpace($bytes, $at);

                if (($bytes[$at] ?? '') === ']') {
                    ++$at;

                    return $items;
                }

                $items[] = self::parse($bytes, $at, $depth + 1);
            }
        }

        if ($char === '/') {
            $length = strcspn($bytes, self::WHITESPACE . self::DELIMITERS, $at + 1);
            $name = substr($bytes, $at + 1, $length);
            $at += 1 + $length;

            return new PdfName($name);
        }

        if ($char === '(') {
            $end = self::literalStringEnd($bytes, $at);
            $string = new PdfString(substr($bytes, $at, $end - $at));
            $at = $end;

            return $string;
        }

        if ($char === '<') {
            $close = strpos($bytes, '>', $at);

            if ($close === false) {
                throw new PdfStructureNotSupported('A PDF hex string does not end.');
            }

            $string = new PdfString(substr($bytes, $at, $close + 1 - $at));
            $at = $close + 1;

            return $string;
        }

        $length = strcspn($bytes, self::WHITESPACE . self::DELIMITERS, $at);

        if ($length === 0) {
            throw new PdfStructureNotSupported(sprintf('Unexpected "%s" in a PDF value.', $char));
        }

        $token = substr($bytes, $at, $length);
        $at += $length;

        if ($token === 'true' || $token === 'false' || $token === 'null') {
            return $token;
        }

        if (!self::isNumber($token)) {
            throw new PdfStructureNotSupported(sprintf('Unexpected "%s" in a PDF value.', substr($token, 0, 20)));
        }

        // `12 0 R`
        if (ctype_digit($token) && preg_match('/\G[\x00\t\n\x0C\r ]+0[\x00\t\n\x0C\r ]+R(?![^\x00\t\n\x0C\r ()<>\[\]{}\/%])/', $bytes, $reference, 0, $at) === 1) {
            $at += strlen($reference[0]);

            return new PdfRef((int) $token);
        }

        return $token;
    }

    public static function serialize(mixed $value): string
    {
        if ($value instanceof PdfDict) {
            $out = '<<';

            foreach ($value->entries as $key => $entry) {
                $out .= ' /' . $key . ' ' . self::serialize($entry);
            }

            return $out . ' >>';
        }

        if (is_array($value)) {
            return '[ ' . implode(' ', array_map(self::serialize(...), $value)) . ' ]';
        }

        if ($value instanceof PdfName) {
            return '/' . $value->value;
        }

        if ($value instanceof PdfRef) {
            return $value->number . ' 0 R';
        }

        if ($value instanceof PdfString) {
            return $value->raw;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return self::number($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        throw new \LogicException('Not a PDF value.');
    }

    /** A number for a colour operand or a coordinate: at most 4 decimals, never an exponent. */
    public static function number(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $text === '-0' || $text === '' ? '0' : $text;
    }

    public static function isNumber(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)$/', $value) === 1;
    }

    /**
     * A content stream, operation by operation: `$handle(operator, operands)`
     * is called for each with the operands as they were written (numbers,
     * `/Names`, strings, and for arrays / dictionaries their whole text) and
     * answers the text that replaces the operation, or null to keep it.
     *
     * @param callable(string, list<string>): (null|string) $handle
     *
     * @throws PdfStructureNotSupported the stream holds an inline image or does not parse
     */
    public static function operations(string $content, callable $handle): string
    {
        $out = '';
        $operands = [];
        $at = 0;
        $length = strlen($content);
        $stops = self::WHITESPACE . self::DELIMITERS;

        while (true) {
            $at += strspn($content, self::WHITESPACE, $at);

            if ($at >= $length) {
                break;
            }

            $char = $content[$at];

            if ($char === '%') {
                $at += strcspn($content, "\r\n", $at);

                continue;
            }

            if ($char === '/') {
                $span = 1 + strcspn($content, $stops, $at + 1);
                $operands[] = substr($content, $at, $span);
                $at += $span;

                continue;
            }

            if ($char === '(') {
                $end = self::literalStringEnd($content, $at);
                $operands[] = substr($content, $at, $end - $at);
                $at = $end;

                continue;
            }

            if ($char === '[' || ($char === '<' && ($content[$at + 1] ?? '') === '<')) {
                // a TJ array or the property list of a marked-content operator: kept whole
                $start = $at;
                self::parse($content, $at);
                $operands[] = substr($content, $start, $at - $start);

                continue;
            }

            if ($char === '<') {
                $close = strpos($content, '>', $at);

                if ($close === false) {
                    throw new PdfStructureNotSupported('A hex string in a content stream does not end.');
                }

                $operands[] = substr($content, $at, $close + 1 - $at);
                $at = $close + 1;

                continue;
            }

            $span = strcspn($content, $stops, $at);

            if ($span === 0) {
                throw new PdfStructureNotSupported(sprintf('Unexpected "%s" in a content stream.', $char));
            }

            $token = substr($content, $at, $span);
            $at += $span;
            $first = $token[0];

            if (($first >= '0' && $first <= '9') || $first === '-' || $first === '+' || $first === '.') {
                if (!self::isNumber($token)) {
                    throw new PdfStructureNotSupported(sprintf('Unexpected "%s" in a content stream.', substr($token, 0, 20)));
                }

                $operands[] = $token;

                continue;
            }

            if ($token === 'true' || $token === 'false' || $token === 'null') {
                $operands[] = $token;

                continue;
            }

            if ($token === 'BI') {
                throw new PdfStructureNotSupported('A content stream holds an inline image.');
            }

            $replacement = $handle($token, $operands);

            if ($replacement !== null) {
                $out .= $replacement . "\n";
            } elseif ($operands === []) {
                $out .= $token . "\n";
            } else {
                $out .= implode(' ', $operands) . ' ' . $token . "\n";
            }

            $operands = [];
        }

        if ($operands !== []) {
            throw new PdfStructureNotSupported('A content stream ends with operands that no operator takes.');
        }

        return $out;
    }

    /** The index right behind the literal string that starts at `$at`. */
    private static function literalStringEnd(string $bytes, int $at): int
    {
        $depth = 0;
        $length = strlen($bytes);

        for ($i = $at; $i < $length; ++$i) {
            $i += strcspn($bytes, '()\\', $i);

            if ($i >= $length) {
                break;
            }

            $char = $bytes[$i];

            if ($char === '\\') {
                ++$i;
            } elseif ($char === '(') {
                ++$depth;
            } else {
                --$depth;

                if ($depth === 0) {
                    return $i + 1;
                }
            }
        }

        throw new PdfStructureNotSupported('A PDF string does not end.');
    }

    private static function skipSpace(string $bytes, int &$at): void
    {
        while (true) {
            $at += strspn($bytes, self::WHITESPACE, $at);

            if (($bytes[$at] ?? '') !== '%') {
                return;
            }

            $at += strcspn($bytes, "\r\n", $at);
        }
    }
}
