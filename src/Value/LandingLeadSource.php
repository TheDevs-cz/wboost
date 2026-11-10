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

    /** The "Napsat historku" form on `/`: e-mail and name are optional. */
    case Story = 'historka';

    /** The retired "Vyzkoušejte si to" form on `/klienti`; kept for stored rows. */
    case Clients = 'klienti';

    /** The retired "Máte dotaz?" chat bubble on `/klienti`; kept for stored rows. */
    case Chat = 'chat';

    public function label(): string
    {
        return match ($this) {
            self::Designers => 'Grafici (/)',
            self::Story => 'Historka (/)',
            self::Clients => 'Klienti (/klienti)',
            self::Chat => 'Chat (/klienti)',
        };
    }
}
