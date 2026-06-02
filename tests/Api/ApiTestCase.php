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
     * Opt into the current (and historical) behaviour explicitly: the kernel
     * is booted when a test client is created. API Platform 4.1 deprecates
     * leaving this unset (it flips the default to `false` in 5.0), so pinning
     * it to `true` both silences that deprecation and keeps our tests — which
     * rely on a booted kernel for security/container state — working across
     * the upgrade.
     */
    protected static ?bool $alwaysBootKernel = true;

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
