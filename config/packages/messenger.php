<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Liip\ImagineBundle\Message\WarmupCache;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use WBoost\Web\Message\Calendar\RunCalendarExport;

return App::config([
    'framework' => [
        'messenger' => [
            'default_bus' => 'command_bus',
            'buses' => [
                'command_bus' => [
                    'middleware' => [
                        ['id' => 'doctrine_transaction'],
                    ],
                ],
                // Long-running jobs (a calendar PDF export takes minutes): NO
                // transaction around the handler, so the progress it writes
                // is committed — and visible to the polling browser — while
                // it runs. Inject it by name (`#[Autowire(service: 'job_bus')]`);
                // its handlers say `#[AsMessageHandler(bus: 'job_bus')]`.
                'job_bus' => [
                    'middleware' => [],
                ],
            ],
            'failure_transport' => 'failed',
            'transports' => [
                'sync' => [
                    'dsn' => 'sync://',
                ],
                'failed' => [
                    'dsn' => 'doctrine://default?queue_name=failed',
                ],
                'async' => [
                    'dsn' => '%env(MESSENGER_TRANSPORT_DSN)%',
                    'options' => [
                        'auto_setup' => false,
                    ],
                    // Exponential backoff: ~30s, ~5min, ~15min (max_delay caps the
                    // third retry, otherwise 30s * 10^2 would be 50min).
                    'retry_strategy' => [
                        'max_retries' => 3,
                        'delay' => 30000,
                        'multiplier' => 10,
                        'max_delay' => 900000,
                    ],
                ],
            ],
            'routing' => [
                WarmupCache::class => ['senders' => ['async']],
                'WBoost\Web\Events\*' => ['senders' => ['async']],
                SendEmailMessage::class => ['senders' => ['async']],
                RunCalendarExport::class => ['senders' => ['async']],
            ],
        ],
    ],
]);
