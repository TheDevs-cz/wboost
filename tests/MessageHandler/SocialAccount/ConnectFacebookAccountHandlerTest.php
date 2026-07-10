<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\SocialAccount;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\SocialAccount;
use WBoost\Web\Exceptions\SocialAccountAlreadyLinked;
use WBoost\Web\Message\SocialAccount\ConnectFacebookAccount;
use WBoost\Web\MessageHandler\SocialAccount\ConnectFacebookAccountHandler;
use WBoost\Web\Services\Security\TokenCrypto;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

final class ConnectFacebookAccountHandlerTest extends KernelTestCase
{
    public function testConnectsNewAccountWithEncryptedToken(): void
    {
        $handler = self::getContainer()->get(ConnectFacebookAccountHandler::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $tokenCrypto = self::getContainer()->get(TokenCrypto::class);

        $handler(new ConnectFacebookAccount(
            TestDataFixture::USER_2_ID,
            'fb-user-2',
            'fresh-plaintext-token',
            time() + 5_184_000,
            ['public_profile', 'email'],
            'User Two',
        ));
        $entityManager->flush();

        $account = $entityManager->getRepository(SocialAccount::class)
            ->findOneBy(['providerUserId' => 'fb-user-2']);

        self::assertInstanceOf(SocialAccount::class, $account);
        self::assertSame(TestDataFixture::USER_2_ID, $account->user->id->toString());
        // Stored ciphertext, never the plaintext token — but it decrypts back.
        self::assertStringNotContainsString('fresh-plaintext-token', $account->accessToken);
        self::assertSame('fresh-plaintext-token', $tokenCrypto->decrypt($account->accessToken));
        self::assertSame(['public_profile', 'email'], $account->scopes);
        self::assertFalse($account->needsReconnect);
    }

    public function testReconnectSameFacebookAccountUpdatesTokenAndClearsReconnectFlag(): void
    {
        $handler = self::getContainer()->get(ConnectFacebookAccountHandler::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $tokenCrypto = self::getContainer()->get(TokenCrypto::class);

        $account = $entityManager->getRepository(SocialAccount::class)
            ->findOneBy(['providerUserId' => TestDataFixture::SOCIAL_ACCOUNT_1_PROVIDER_USER_ID]);
        self::assertInstanceOf(SocialAccount::class, $account);
        $account->markNeedsReconnect();
        $entityManager->flush();

        $handler(new ConnectFacebookAccount(
            TestDataFixture::USER_1_ID,
            TestDataFixture::SOCIAL_ACCOUNT_1_PROVIDER_USER_ID,
            'renewed-token',
            null,
            ['public_profile', 'email', 'pages_manage_posts'],
            'Renamed FB User',
        ));
        $entityManager->flush();

        self::assertSame('renewed-token', $tokenCrypto->decrypt($account->accessToken));
        self::assertFalse($account->needsReconnect);
        self::assertNull($account->tokenExpiresAt);
        self::assertSame('Renamed FB User', $account->displayName);
    }

    public function testFacebookAccountLinkedToAnotherUserIsRefused(): void
    {
        $handler = self::getContainer()->get(ConnectFacebookAccountHandler::class);

        $this->expectException(SocialAccountAlreadyLinked::class);

        $handler(new ConnectFacebookAccount(
            TestDataFixture::USER_2_ID,
            TestDataFixture::SOCIAL_ACCOUNT_1_PROVIDER_USER_ID,
            'token',
            null,
            [],
            null,
        ));
    }

    public function testSwitchingToDifferentFacebookAccountReplacesTheLink(): void
    {
        $handler = self::getContainer()->get(ConnectFacebookAccountHandler::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $handler(new ConnectFacebookAccount(
            TestDataFixture::USER_1_ID,
            'fb-user-1-new-identity',
            'token-for-new-identity',
            null,
            ['public_profile', 'email'],
            null,
        ));
        $entityManager->flush();

        $repository = $entityManager->getRepository(SocialAccount::class);

        self::assertNull($repository->findOneBy(['providerUserId' => TestDataFixture::SOCIAL_ACCOUNT_1_PROVIDER_USER_ID]));

        $replacement = $repository->findOneBy(['providerUserId' => 'fb-user-1-new-identity']);
        self::assertInstanceOf(SocialAccount::class, $replacement);
        self::assertSame(TestDataFixture::USER_1_ID, $replacement->user->id->toString());
    }
}
