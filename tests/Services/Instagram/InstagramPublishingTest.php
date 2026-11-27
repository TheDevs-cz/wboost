<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Instagram;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use WBoost\Web\Entity\User;
use WBoost\Web\Services\Instagram\InstagramPublishing;

final class InstagramPublishingTest extends TestCase
{
    private function publishing(bool $admin, string $appId = 'id', string $rollout = 'admins', bool $loggedIn = true, string $key = 'cHJvZC1rZXk=', string $env = 'prod', string $configId = 'cfg'): InstagramPublishing
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($loggedIn ? (new \ReflectionClass(User::class))->newInstanceWithoutConstructor() : null);
        $security->method('isGranted')->willReturn($admin);

        return new InstagramPublishing($security, $appId, 'secret', $configId, $rollout, $key, $env);
    }

    public function testAdminsRolloutIsAdminOnly(): void
    {
        self::assertTrue($this->publishing(admin: true)->isAvailable());
        self::assertFalse($this->publishing(admin: false)->isAvailable());
    }

    public function testEveryoneRollout(): void
    {
        self::assertTrue($this->publishing(admin: false, rollout: 'everyone')->isAvailable());
        self::assertFalse($this->publishing(admin: false, rollout: 'everyone', loggedIn: false)->isAvailable());
    }

    public function testNoCredentialsMeansOff(): void
    {
        self::assertFalse($this->publishing(admin: true, appId: '', rollout: 'everyone')->isAvailable());
        self::assertFalse($this->publishing(admin: true, appId: '')->isConfigured());
        self::assertFalse($this->publishing(admin: true, configId: '')->isConfigured());
    }

    public function testProdRefusesMissingOrCommittedDevEncryptionKey(): void
    {
        self::assertFalse($this->publishing(admin: true, key: '')->isConfigured());
        self::assertFalse($this->publishing(admin: true, key: 'Oe9omNp9iaQgTR3NVOzC+CfqmgVnIgEUrd75mEIXOoA=')->isConfigured());
        // Dev / test may use the committed key.
        self::assertTrue($this->publishing(admin: true, key: 'Oe9omNp9iaQgTR3NVOzC+CfqmgVnIgEUrd75mEIXOoA=', env: 'dev')->isConfigured());
    }
}
