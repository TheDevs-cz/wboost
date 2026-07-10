<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\SocialAccount;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\SocialAccount;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * Meta's data-deletion callback: HMAC-verified signed_request drops the
 * SocialAccount and answers {url, confirmation_code}; a bad signature is a
 * hard 400 (anyone could otherwise unlink accounts by guessing ids).
 */
final class FacebookDataDeletionControllerTest extends WebTestCase
{
    private const string APP_SECRET = 'test-app-secret';

    public function testValidSignedRequestDeletesAccountAndConfirms(): void
    {
        $client = self::createClient();

        $client->request('POST', '/oauth/facebook/data-deletion', [
            'signed_request' => $this->signedRequest([
                'user_id' => TestDataFixture::SOCIAL_ACCOUNT_1_PROVIDER_USER_ID,
                'algorithm' => 'HMAC-SHA256',
            ]),
        ]);

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);

        $confirmationCode = $payload['confirmation_code'] ?? null;
        self::assertIsString($confirmationCode);

        $statusUrl = $payload['url'] ?? null;
        self::assertIsString($statusUrl);
        self::assertStringContainsString('/oauth/facebook/data-deletion/status?code=', $statusUrl);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($entityManager->getRepository(SocialAccount::class)->findOneBy([
            'providerUserId' => TestDataFixture::SOCIAL_ACCOUNT_1_PROVIDER_USER_ID,
        ]));

        // The status URL is public and renders for the person.
        $client->request('GET', '/oauth/facebook/data-deletion/status?code=' . $confirmationCode);
        self::assertResponseIsSuccessful();
    }

    public function testInvalidSignatureIsRejectedAndNothingIsDeleted(): void
    {
        $client = self::createClient();

        $payload = $this->base64UrlEncode((string) json_encode([
            'user_id' => TestDataFixture::SOCIAL_ACCOUNT_1_PROVIDER_USER_ID,
            'algorithm' => 'HMAC-SHA256',
        ]));
        $forgedSignature = $this->base64UrlEncode(hash_hmac('sha256', $payload, 'wrong-secret', true));

        $client->request('POST', '/oauth/facebook/data-deletion', [
            'signed_request' => $forgedSignature . '.' . $payload,
        ]);

        self::assertResponseStatusCodeSame(400);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(SocialAccount::class, $entityManager->getRepository(SocialAccount::class)->findOneBy([
            'providerUserId' => TestDataFixture::SOCIAL_ACCOUNT_1_PROVIDER_USER_ID,
        ]));
    }

    public function testUnknownUserIdIsIdempotentSuccess(): void
    {
        $client = self::createClient();

        $client->request('POST', '/oauth/facebook/data-deletion', [
            'signed_request' => $this->signedRequest([
                'user_id' => 'never-connected-user',
                'algorithm' => 'HMAC-SHA256',
            ]),
        ]);

        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, string> $payload
     */
    private function signedRequest(array $payload): string
    {
        $encodedPayload = $this->base64UrlEncode((string) json_encode($payload));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $encodedPayload, self::APP_SECRET, true));

        return $signature . '.' . $encodedPayload;
    }

    private function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }
}
