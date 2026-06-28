<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Security;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use WBoost\Web\Entity\User;
use WBoost\Web\Services\Security\UserChecker;

final class UserCheckerTest extends TestCase
{
    public function testUnconfirmedUserIsRejected(): void
    {
        $this->expectException(CustomUserMessageAccountStatusException::class);

        $user = new User(Uuid::uuid4(), 'pending@test.cz', new DateTimeImmutable(), false);

        (new UserChecker())->checkPreAuth($user);
    }

    public function testConfirmedUserIsAllowed(): void
    {
        $user = new User(Uuid::uuid4(), 'active@test.cz', new DateTimeImmutable(), true);

        (new UserChecker())->checkPreAuth($user);

        $this->expectNotToPerformAssertions();
    }
}
