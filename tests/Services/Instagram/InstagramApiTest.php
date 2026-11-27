<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Instagram;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use WBoost\Web\Exceptions\InstagramPermissionMissing;
use WBoost\Web\Exceptions\InstagramRateLimited;
use WBoost\Web\Exceptions\InstagramTokenExpired;
use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Services\Instagram\InstagramApi;

/**
 * The real client against Meta's documented response shapes (the rest of the
 * suite uses FakeInstagramApi, so this is the only check of the wire format).
 */
final class InstagramApiTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];

    /**
     * @param list<MockResponse> $responses
     */
    private function api(array $responses): InstagramApi
    {
        $this->requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $body = $options['body'] ?? '';
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => is_string($body) ? $body : ''];

            return array_shift($responses) ?? new MockResponse('{}');
        }, 'https://graph.facebook.com/v27.0/');

        return new InstagramApi($client, 'meta-app', 'meta-secret', 'cfg-1');
    }

    public function testAuthorizationUrl(): void
    {
        $url = $this->api([])->authorizationUrl('https://wboost.cz/oauth/instagram/check', 'st');

        self::assertStringStartsWith('https://www.facebook.com/dialog/oauth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame([
            'client_id' => 'meta-app',
            'config_id' => 'cfg-1',
            'redirect_uri' => 'https://wboost.cz/oauth/instagram/check',
            'response_type' => 'code',
            'override_default_response_type' => 'true',
            'state' => 'st',
            'prompt' => 'login',
        ], $query);
    }

    public function testCodeExchangeWithoutExpiryMeansNeverExpires(): void
    {
        $token = $this->api([new MockResponse('{"access_token":"bisu","token_type":"bearer"}')])
            ->exchangeCode('abc#_', 'https://wboost.cz/oauth/instagram/check');

        self::assertSame('bisu', $token->accessToken);
        self::assertNull($token->expiresAtTimestamp);
        self::assertSame('GET', $this->requests[0]['method']);
        self::assertStringStartsWith('https://graph.facebook.com/v27.0/oauth/access_token?', $this->requests[0]['url']);
        parse_str((string) parse_url($this->requests[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame('abc', $query['code'], 'the trailing #_ is not part of the code');
        self::assertSame('meta-app', $query['client_id']);
        self::assertSame('meta-secret', $query['client_secret']);
        self::assertSame('https://wboost.cz/oauth/instagram/check', $query['redirect_uri']);
    }

    public function testCodeExchangeWithExpiry(): void
    {
        $token = $this->api([new MockResponse('{"access_token":"bisu","token_type":"bearer","expires_in":5183944}')])
            ->exchangeCode('abc', 'https://x');

        self::assertNotNull($token->expiresAtTimestamp);
        self::assertGreaterThan(time() + 5_000_000, $token->expiresAtTimestamp);
    }

    public function testAssignedAccountsAndOwnerCarryAppSecretProof(): void
    {
        $api = $this->api([
            new MockResponse('{"data":[{"id":"1784","username":"kavarna"},{"id":"1785","username":"pekarna","name":"Pekárna"},{"id":"x"}],"paging":{"cursors":{}}}'),
            new MockResponse('{"id":"900","client_business_id":"555"}'),
        ]);

        $accounts = $api->assignedAccounts('tok');
        self::assertCount(2, $accounts, 'rows without a username are skipped');
        self::assertSame('1784', $accounts[0]->userId);
        self::assertSame('kavarna', $accounts[0]->username);
        self::assertSame('Pekárna', $accounts[1]->name);
        self::assertStringStartsWith('https://graph.facebook.com/v27.0/me/assigned_instagram_accounts?', $this->requests[0]['url']);
        parse_str((string) parse_url($this->requests[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame('tok', $query['access_token']);
        self::assertSame(hash_hmac('sha256', 'tok', 'meta-secret'), $query['appsecret_proof']);

        self::assertSame('900', $api->tokenOwnerId('tok'));
        self::assertStringStartsWith('https://graph.facebook.com/v27.0/me?', $this->requests[1]['url']);
    }

    public function testFeedContainerCarriesCaptionStoryDoesNot(): void
    {
        $api = $this->api([new MockResponse('{"id":"c1"}'), new MockResponse('{"id":"c2"}')]);

        self::assertSame('c1', $api->createImageContainer('1784', 't', 'https://img/x.jpg', 'Ahoj #wboost', false));
        parse_str($this->requests[0]['body'], $feed);
        self::assertSame('https://graph.facebook.com/v27.0/1784/media', $this->requests[0]['url']);
        self::assertSame('Ahoj #wboost', $feed['caption']);
        self::assertSame(hash_hmac('sha256', 't', 'meta-secret'), $feed['appsecret_proof']);
        self::assertArrayNotHasKey('media_type', $feed);

        self::assertSame('c2', $api->createImageContainer('1784', 't', 'https://img/x.jpg', 'ignored', true));
        parse_str($this->requests[1]['body'], $story);
        self::assertSame('STORIES', $story['media_type']);
        self::assertArrayNotHasKey('caption', $story);
    }

    public function testGraphErrorsMapToTypedExceptions(): void
    {
        foreach ([
            [190, InstagramTokenExpired::class],
            [10, InstagramPermissionMissing::class],
            [4, InstagramRateLimited::class],
            [100, MetaApiError::class],
        ] as [$code, $class]) {
            try {
                $this->api([new MockResponse((string) json_encode(['error' => ['message' => 'x', 'code' => $code]]), ['http_code' => 400])])
                    ->publishContainer('1784', 't', 'c1');
                self::fail('expected an exception');
            } catch (MetaApiError $error) {
                self::assertSame($class, $error::class);
                self::assertSame($code, $error->graphCode);
            }
        }
    }

    public function testCodeExchangeErrorCarriesMetasMessage(): void
    {
        $this->expectException(MetaApiError::class);
        $this->expectExceptionMessage('This authorization code has been used.');

        $this->api([new MockResponse('{"error":{"message":"This authorization code has been used.","type":"OAuthException","code":100}}', ['http_code' => 400])])
            ->exchangeCode('bad', 'https://x');
    }
}
