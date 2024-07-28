<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthCheckLivenessControllerTest extends WebTestCase
{
    public function testResponseIsOk(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/-/health-check/liveness');

        $this->assertResponseIsSuccessful();
    }
}
