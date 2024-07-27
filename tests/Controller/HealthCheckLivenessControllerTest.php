<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthCheckLivenessControllerTest extends WebTestCase
{
    public function testResponseIsOk(): void
    {
        $client = self::createClient();

        $client->request('GET', '/-/health-check/liveness');

        $this->assertResponseIsSuccessful();
    }
}