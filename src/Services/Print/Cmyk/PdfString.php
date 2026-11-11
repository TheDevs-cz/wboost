<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print\Cmyk;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A PDF string kept exactly as written, delimiters included (`(text)` or `<0A1B>`). */
#[Exclude]
readonly final class PdfString
{
    public function __construct(
        public string $raw,
    ) {
    }

    /** The bytes the string stands for. */
    public function bytes(): string
    {
        if (str_starts_with($this->raw, '<')) {
            $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', $this->raw);

            if (strlen($hex) % 2 === 1) {
                $hex .= '0';
            }

            return (string) hex2bin($hex);
        }

        $inner = substr($this->raw, 1, -1);
        $out = '';

        for ($i = 0, $length = strlen($inner); $i < $length; ++$i) {
            $char = $inner[$i];

            if ($char !== '\\') {
                $out .= $char;

                continue;
            }

            $next = $inner[++$i] ?? '';

            if ($next === "\r" || $next === "\n") {
                // a line continuation
                if ($next === "\r" && ($inner[$i + 1] ?? '') === "\n") {
                    ++$i;
                }

                continue;
            }

            if ($next >= '0' && $next <= '7') {
                $octal = $next;

                while (strlen($octal) < 3 && ($inner[$i + 1] ?? '') >= '0' && ($inner[$i + 1] ?? '') <= '7') {
                    $octal .= $inner[++$i];
                }

                $out .= chr(((int) octdec($octal)) & 0xFF);

                continue;
            }

            $out .= match ($next) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'b' => "\x08",
                'f' => "\x0C",
                default => $next,
            };
        }

        return $out;
    }
}
