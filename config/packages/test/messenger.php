<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use WBoost\Web\Message\Calendar\RunCalendarExport;

return App::config([
    'framework' => [
        'messenger' => [
            'routing' => [
                // Send mail synchronously so Symfony's mailer test assertions
                // (assertEmailCount, …) can observe the dispatched messages.
                SendEmailMessage::class => ['senders' => ['sync']],
                // The PDF export job runs inside the request that asked for
                // it, so a test sees its result at once (no consumer in tests).
                RunCalendarExport::class => ['senders' => ['sync']],
            ],
        ],
    ],
]);
