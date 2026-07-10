<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * Graph error 36003 / subcode 2207009: Instagram feed photos only accept
 * aspect ratios between 4:5 and 1.91:1 — story-format templates (9:16) and
 * other extreme ratios are rejected at container creation.
 */
final class InstagramUnsupportedImage extends MetaApiError
{
    public function userMessage(): string
    {
        return 'Instagram tento formát obrázku nepodporuje — příspěvky povolují poměr stran mezi 4:5 a 1,91:1 (šablony na výšku 9:16 publikovat nelze).';
    }
}
