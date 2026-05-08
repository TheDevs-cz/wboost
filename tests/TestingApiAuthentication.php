<?php

declare(strict_types=1);

namespace WBoost\Web\Tests;

use ApiPlatform\Symfony\Bundle\Test\Client;

/**
 * Helper for tests that need an OAuth2 access token. Goes through the real
 * `/api/token` endpoint (client_credentials grant) — that's the contract being
 * exercised, so we don't shortcut it.
 */
readonly final class TestingApiAuthentication
{
    public static function getAccessToken(Client $client, string $clientId, string $clientSecret): string
    {
        $response = $client->request('POST', '/api/token', [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ],
            'extra' => [
                'parameters' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ],
            ],
        ]);

        $payload = $response->toArray(false);
        \assert(\is_string($payload['access_token'] ?? null), 'Token endpoint did not return an access_token.');

        return $payload['access_token'];
    }
}
