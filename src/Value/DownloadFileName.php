<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use Normalizer;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\String\UnicodeString;

/**
 * The name a downloaded file is saved under — ONE builder for every download
 * the app hands out (templates, groups, documents, manual logos, …) so they
 * all read alike: human parts joined by " - ", e.g. `Kampaň - 1x1.png`.
 *
 * Safe on every OS: control / zero-width characters and the reserved
 * `/\:*?"<>|` are removed (a reserved run next to whitespace or an edge
 * just drops — "Pozor: změna" → "Pozor změna", `"finále"` → "finále" —
 * inside a word it becomes "-": "1/2" → "1-2"), whitespace collapses, no leading/trailing dots, Windows
 * device names get a "_", and the whole name is capped at {@see MAX_LENGTH}
 * characters (cut at a word boundary, extension kept).
 *
 * `$name` keeps Czech diacritics (sent as RFC 6266 `filename*`);
 * `$asciiName` is its transliterated fallback (`filename`) for clients that
 * ignore `filename*` — {@see disposition()}.
 */
final readonly class DownloadFileName
{
    public const int MAX_LENGTH = 120;

    private const string SEPARATOR = ' - ';

    private const string RESERVED = '/\\\\:*?"<>|';

    /** Typography {@see UnicodeString::ascii()} would turn into reserved or noisy ASCII. */
    private const array ASCII_PREMAP = [
        '×' => 'x',
        '„' => '', '“' => '', '”' => '', '‚' => '', '‘' => '', '’' => "'", '«' => '', '»' => '',
    ];

    private function __construct(
        private string $base,
        private string $extension,
        public string $name,
        public string $asciiName,
    ) {
    }

    /**
     * @param list<null|string> $parts human labels in order; null / blank ones are skipped
     * @param string $extension without the dot ("png", "zip", …)
     * @param string $fallback the base when no part leaves anything usable
     */
    public static function fromParts(array $parts, string $extension, string $fallback = 'export'): self
    {
        $clean = [];

        foreach ($parts as $part) {
            if ($part === null) {
                continue;
            }

            $part = self::sanitize($part);

            if ($part !== '') {
                $clean[] = $part;
            }
        }

        $base = implode(self::SEPARATOR, $clean);

        if ($base === '') {
            $base = self::sanitize($fallback);
        }

        return self::build($base !== '' ? $base : 'export', strtolower((string) preg_replace('/[^a-z0-9]/i', '', $extension)));
    }

    /**
     * The same name with a counter — two archive entries that would collide:
     * `Kampaň - 1x1 (2).png`.
     */
    public function numbered(int $counter): self
    {
        return self::build(sprintf('%s (%d)', self::truncate($this->base, self::MAX_LENGTH - 16), $counter), $this->extension);
    }

    /**
     * `attachment; filename="<ascii>"; filename*=utf-8''<utf-8>` — the
     * `filename*` half only when the two differ.
     */
    public function disposition(string $type = HeaderUtils::DISPOSITION_ATTACHMENT): string
    {
        return HeaderUtils::makeDisposition($type, $this->name, $this->asciiName);
    }

    private static function build(string $base, string $extension): self
    {
        $suffix = $extension !== '' ? '.' . $extension : '';
        $base = self::truncate($base, self::MAX_LENGTH - mb_strlen($suffix));

        $asciiBase = self::sanitize(strtr((string) (new UnicodeString(strtr($base, self::ASCII_PREMAP)))->ascii(), ['%' => '']));
        $asciiBase = (string) preg_replace('/[^\x20-\x7E]/', '', $asciiBase);
        $asciiBase = self::truncate(self::sanitize($asciiBase), self::MAX_LENGTH - strlen($suffix));

        return new self($base, $extension, $base . $suffix, ($asciiBase !== '' ? $asciiBase : 'export') . $suffix);
    }

    private static function sanitize(string $value): string
    {
        $value = Normalizer::normalize($value, Normalizer::FORM_C) ?: $value;
        // Line breaks / tabs separate words; other control and format
        // characters (BEL, zero-width space, BOM, bidi marks) just vanish.
        $value = (string) preg_replace('/[\t\r\n\x{2028}\x{2029}]+/u', ' ', $value);
        $value = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $value);
        $value = (string) preg_replace('~(?<!\S)[' . self::RESERVED . ']+|[' . self::RESERVED . ']+(?!\S)~u', '', $value);
        $value = (string) preg_replace('~[' . self::RESERVED . ']+~u', '-', $value);
        $value = (string) preg_replace('/[\s\p{Z}]+/u', ' ', $value);
        $value = trim($value, " .-");

        // CON, PRN, AUX, NUL, COM1-9, LPT1-9 are device names on Windows.
        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $value) === 1) {
            $value .= '_';
        }

        return $value;
    }

    private static function truncate(string $value, int $length): string
    {
        $string = new UnicodeString($value);

        if ($string->length() <= $length) {
            return $value;
        }

        $cut = (string) $string->slice(0, $length);
        $lastSpace = mb_strrpos($cut, ' ');

        // Prefer a word boundary unless that would throw most of it away.
        if ($lastSpace !== false && $lastSpace > $length / 2) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " .-");
    }
}
