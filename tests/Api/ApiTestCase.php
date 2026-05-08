<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase as BaseApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;

/**
 * Base test case for API tests. Sets a default Accept header so individual
 * tests stay terse — JSON-LD by default for Hydra-shaped collection assertions.
 */
abstract class ApiTestCase extends BaseApiTestCase
{
    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $defaultOptions
     */
    protected static function createClient(array $options = [], array $defaultOptions = []): Client
    {
        $defaultOptions = array_merge_recursive([
            'headers' => [
                'Accept' => 'application/ld+json',
            ],
        ], $defaultOptions);

        return parent::createClient($options, $defaultOptions);
    }
}
