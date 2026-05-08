<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'league_oauth2_server' => [
        'authorization_server' => [
            'private_key' => '%env(base64:OAUTH2_PRIVATE_KEY)%',
            'private_key_passphrase' => '%env(OAUTH2_PRIVATE_KEY_PASSPHRASE)%',
            'encryption_key' => '%env(OAUTH2_ENCRYPTION_KEY)%',
            'encryption_key_type' => 'plain',
            'access_token_ttl' => 'PT1H',
            'enable_client_credentials_grant' => true,
            'enable_password_grant' => false,
            'enable_refresh_token_grant' => false,
            'enable_auth_code_grant' => false,
            'enable_implicit_grant' => false,
            'persist_access_token' => true,
        ],
        'resource_server' => [
            'public_key' => '%env(base64:OAUTH2_PUBLIC_KEY)%',
        ],
        'scopes' => [
            'available' => ['api'],
            'default' => ['api'],
        ],
        'persistence' => [
            'doctrine' => [
                'entity_manager' => 'default',
            ],
        ],
        'role_prefix' => 'ROLE_OAUTH2_',
    ],
]);
