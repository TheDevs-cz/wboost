<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * An uploaded font file the app cannot take: a format php-font-lib does not
 * parse (WOFF2 above all — Chromium would render it, but the face metadata the
 * WYSIWYG's bold/italic mapping and the MCP text measurement rely on could not
 * be read), or a file whose tables are corrupt. Carries a user-facing Czech
 * message; the upload surfaces show it verbatim.
 */
final class UnsupportedFontFile extends \Exception
{
    public static function woff2(string $fileName): self
    {
        return new self(sprintf(
            'Soubor „%s“ je WOFF2, který zatím nepodporujeme — nahrajte prosím TTF, OTF nebo WOFF verzi písma.',
            $fileName,
        ));
    }

    public static function unreadable(string $fileName): self
    {
        return new self(sprintf(
            'Soubor „%s“ se nepodařilo přečíst jako písmo. Podporované formáty jsou TTF, OTF a WOFF.',
            $fileName,
        ));
    }
}
