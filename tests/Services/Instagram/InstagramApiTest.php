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
        }, 'https://graph.instagram.com/v24.0/');

        return new InstagramApi($client, 'ig-app', 'ig-secret');
    }

    public function testAuthorizationUrl(): void
    {
        $url = $this->api([])->authorizationUrl('https://wboost.cz/oauth/instagram/check', 'st', ['instagram_business_basic', 'instagram_business_content_publish']);

        self::assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame([
            'client_id' => 'ig-app',
            'redirect_uri' => 'https://wboost.cz/oauth/instagram/check',
            'response_type' => 'code',
            'scope' => 'instagram_business_basic,instagram_business_content_publish',
            'state' => 'st',
            'force_reauth' => 'true',
        ], $query);
    }

    public function testCodeExchangeReadsWrappedResponseWithCommaPermissions(): void
    {
        $api = $this->api([new MockResponse((string) json_encode(['data' => [[
            'access_token' => 'short',
            'user_id' => 17841400000000001,
            'permissions' => 'instagram_business_basic,instagram_business_content_publish',
        ]]]))]);

        $token = $api->exchangeCode('abc#_', 'https://wboost.cz/oauth/instagram/check');

        self::assertSame('short', $token->accessToken);
        self::assertSame('17841400000000001', $token->userId);
        self::assertSame(['instagram_business_basic', 'instagram_business_content_publish'], $token->permissions);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://api.instagram.com/oauth/access_token', $this->requests[0]['url']);
        parse_str($this->requests[0]['body'], $form);
        self::assertSame('abc', $form['code'], 'the trailing #_ is not part of the code');
        self::assertSame('authorization_code', $form['grant_type']);
        self::assertSame('ig-secret', $form['client_secret']);
    }

    public function testCodeExchangeReadsFlatResponseAndPermissionArray(): void
    {
        $token = $this->api([new MockResponse((string) json_encode([
            'access_token' => 'short',
            'user_id' => '42',
            'permissions' => ['instagram_business_basic'],
        ]))])->exchangeCode('abc', 'https://x');

        self::assertSame('42', $token->userId);
        self::assertSame(['instagram_business_basic'], $token->permissions);
    }

    public function testLongLivedExchangeAndRefresh(): void
    {
        $api = $this->api([
            new MockResponse('{"access_token":"long","token_type":"bearer","expires_in":5183944}'),
            new MockResponse('{"access_token":"renewed","token_type":"bearer","expires_in":5183944}'),
        ]);

        $long = $api->exchangeLongLivedToken('short');
        self::assertSame('long', $long->accessToken);
        self::assertNotNull($long->expiresAtTimestamp);
        self::assertGreaterThan(time() + 5_000_000, $long->expiresAtTimestamp);
        self::assertStringStartsWith('https://graph.instagram.com/access_token?', $this->requests[0]['url']);
        self::assertStringContainsString('grant_type=ig_exchange_token', $this->requests[0]['url']);

        self::assertSame('renewed', $api->refreshToken('long')->accessToken);
        self::assertStringStartsWith('https://graph.instagram.com/refresh_access_token?', $this->requests[1]['url']);
        self::assertStringContainsString('grant_type=ig_refresh_token', $this->requests[1]['url']);
    }

    public function testProfileFromWrappedResponse(): void
    {
        $profile = $this->api([new MockResponse('{"data":[{"id":"900","user_id":"1784","username":"kavarna","account_type":"BUSINESS"}]}')])
            ->fetchProfile('t');

        self::assertSame('1784', $profile->userId);
        self::assertSame('900', $profile->appScopedId);
        self::assertSame('kavarna', $profile->username);
        self::assertStringStartsWith('https://graph.instagram.com/v24.0/me?', $this->requests[0]['url']);
    }

    public function testFeedContainerCarriesCaptionStoryDoesNot(): void
    {
        $api = $this->api([new MockResponse('{"id":"c1"}'), new MockResponse('{"id":"c2"}')]);

        self::assertSame('c1', $api->createImageContainer('1784', 't', 'https://img/x.jpg', 'Ahoj #wboost', false));
        parse_str($this->requests[0]['body'], $feed);
        self::assertSame('https://graph.instagram.com/v24.0/1784/media', $this->requests[0]['url']);
        self::assertSame('Ahoj #wboost', $feed['caption']);
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

    public function testTokenEndpointErrorShape(): void
    {
        $this->expectException(MetaApiError::class);
        $this->expectExceptionMessage('Invalid authorization code');

        $this->api([new MockResponse('{"error_type":"OAuthException","code":400,"error_message":"Invalid authorization code"}', ['http_code' => 400])])
            ->exchangeCode('bad', 'https://x');
    }
}
