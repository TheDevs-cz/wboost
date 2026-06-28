<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\User;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use WBoost\Web\Exceptions\UserNotRegistered;
use WBoost\Web\Message\User\RequestPasswordReset;
use WBoost\Web\MessageHandler\User\RequestPasswordResetHandler;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

final class RequestPasswordResetHandlerTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    public function testSendsResetEmailToRegisteredUser(): void
    {
        $handler = self::getContainer()->get(RequestPasswordResetHandler::class);

        $handler(new RequestPasswordReset(TestDataFixture::USER_1_EMAIL));

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHeaderSame($email, 'Subject', 'Obnovení hesla');
        self::assertEmailAddressContains($email, 'To', TestDataFixture::USER_1_EMAIL);
    }

    public function testUnknownEmailThrows(): void
    {
        $this->expectException(UserNotRegistered::class);

        $handler = self::getContainer()->get(RequestPasswordResetHandler::class);
        $handler(new RequestPasswordReset('nobody@test.cz'));
    }
}
