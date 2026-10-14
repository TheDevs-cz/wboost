<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * Where on the static wboost.cz landing site a lead was captured.
 */
enum LandingLeadSource: string
{
    /** The contact form on `/` — the page for graphic designers. */
    case Designers = 'grafici';

    /** The "Vyzkoušejte si to" form on `/klienti`. */
    case Clients = 'klienti';

    /** The "Máte dotaz?" chat bubble on `/klienti`. */
    case Chat = 'chat';

    public function label(): string
    {
        return match ($this) {
            self::Designers => 'Grafici (/)',
            self::Clients => 'Klienti (/klienti)',
            self::Chat => 'Chat (/klienti)',
        };
    }
}
