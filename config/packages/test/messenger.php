<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\Mailer\Messenger\SendEmailMessage;

return App::config([
    'framework' => [
        'messenger' => [
            'routing' => [
                // Send mail synchronously so Symfony's mailer test assertions
                // (assertEmailCount, …) can observe the dispatched messages.
                SendEmailMessage::class => ['senders' => ['sync']],
            ],
        ],
    ],
]);
