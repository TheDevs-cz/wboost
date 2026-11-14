<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Mail;

/**
 * Turns one of our e-mail templates (templates/emails/_layout.html.twig) into
 * readable plain text: paragraphs and table rows on their own lines, links as
 * "label (url)", buttons as "label: url", nothing from <head>, Outlook
 * conditional comments or elements marked `data-skip-text` (the preheader).
 *
 * Deliberately small — it reads markup we write ourselves, not arbitrary HTML.
 */
final class HtmlToPlainText
{
    public static function convert(string $html): string
    {
        $text = (string) preg_replace([
            '{<head\b.*?</head>}is',
            '{<(style|script)\b.*?</\1>}is',
            '{<!--.*?-->}s',
            '{<div\b[^>]*\bdata-skip-text\b[^>]*>.*?</div>}is',
        ], '', $html);

        // Links: keep the URL. A link whose text IS the URL stays as it is.
        $text = (string) preg_replace_callback(
            '{<a\b[^>]*\bhref="([^"]*)"[^>]*>(.*?)</a>}is',
            static function (array $match): string {
                $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = trim((string) preg_replace('{\s+}u', ' ', strip_tags($match[2])));
                $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if ($label === '' || $label === $url) {
                    return $url;
                }

                return str_contains($match[0], 'e-btn') ? sprintf('%s: %s', $label, $url) : sprintf('%s (%s)', $label, $url);
            },
            $text,
        );

        // A facts-box label cell (emails/_ui.html.twig `facts`) reads "Label: value".
        $text = (string) preg_replace('{<td\b[^>]*\bclass="e-fl"[^>]*>(.*?)</td>\s*}is', '$1: ', $text);
        $text = (string) preg_replace('{<br\s*/?>}i', "\n", $text);
        $text = (string) preg_replace('{</td>\s*<td\b[^>]*>}i', ' ', $text); // label + value cells on one line
        $text = (string) preg_replace('{</?(p|h[1-6]|tr|table|div|li|ul|ol|center)\b[^>]*>}i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\u{200C}", "\u{00A0}"], ['', ' '], $text);

        $lines = array_map(static fn (string $line): string => trim((string) preg_replace('{[ \t]+}u', ' ', $line)), explode("\n", $text));
        $text = (string) preg_replace("{\n{3,}}", "\n\n", implode("\n", $lines));

        return trim($text) . "\n";
    }
}
