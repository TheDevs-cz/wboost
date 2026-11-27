<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\FacebookPage;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use WBoost\Web\Exceptions\FacebookPagePermissionMissing;
use WBoost\Web\Exceptions\FacebookPageRateLimited;
use WBoost\Web\Exceptions\FacebookPageTokenExpired;
use WBoost\Web\Services\FacebookPage\FacebookPageApi;

/**
 * The real Facebook Page client against Meta's documented response shapes
 * (the rest of the suite uses FakeFacebookPageApi).
 */
final class FacebookPageApiTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];

    /**
     * @param list<MockResponse> $responses
     */
    private function api(array $responses): FacebookPageApi
    {
        $this->requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $body = $options['body'] ?? '';

            if ($body instanceof \Closure) {
                $chunks = '';
                while (is_string($chunk = $body(8192)) && $chunk !== '') {
                    $chunks .= $chunk;
                }
                $body = $chunks;
            }

            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => is_string($body) ? $body : ''];

            return array_shift($responses) ?? new MockResponse('{}');
        }, 'https://graph.facebook.com/v27.0/');

        return new FacebookPageApi($client, 'meta-app', 'meta-secret', 'pages-cfg');
    }

    public function testAuthorizationUrlUsesThePagesConfiguration(): void
    {
        $url = $this->api([])->authorizationUrl('https://wboost.cz/oauth/facebook-page/check', 'st');

        self::assertStringStartsWith('https://www.facebook.com/dialog/oauth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('meta-app', $query['client_id']);
        self::assertSame('pages-cfg', $query['config_id']);
        self::assertSame('https://wboost.cz/oauth/facebook-page/check', $query['redirect_uri']);
        self::assertSame('code', $query['response_type']);
        self::assertSame('true', $query['override_default_response_type']);
        self::assertSame('st', $query['state']);
        self::assertArrayNotHasKey('scope', $query);
    }

    public function testCodeExchangeWithoutExpiryNeverExpires(): void
    {
        $token = $this->api([new MockResponse('{"access_token":"bisu","token_type":"bearer"}')])
            ->exchangeCode('abc#_', 'https://wboost.cz/oauth/facebook-page/check');

        self::assertSame('bisu', $token->accessToken);
        self::assertNull($token->expiresAtTimestamp);
        parse_str((string) parse_url($this->requests[0]['url'], PHP_URL_QUERY), $query);
        self::assertStringStartsWith('https://graph.facebook.com/v27.0/oauth/access_token?', $this->requests[0]['url']);
        self::assertSame('abc', $query['code']);
        self::assertSame('meta-secret', $query['client_secret']);
    }

    public function testPagesKeepOnlyThoseContentCanBeCreatedOn(): void
    {
        $pages = $this->api([new MockResponse(json_encode(['data' => [
            ['id' => '101', 'name' => 'Kavárna', 'access_token' => 'pt1', 'tasks' => ['ANALYZE', 'CREATE_CONTENT', 'MODERATE']],
            ['id' => '102', 'name' => 'Jen statistiky', 'access_token' => 'pt2', 'tasks' => ['ANALYZE']],
            ['id' => '103', 'name' => 'Bez úkolů', 'access_token' => 'pt3'],
            ['id' => '104', 'name' => 'Bez tokenu'],
        ], 'paging' => []], JSON_THROW_ON_ERROR))])->pages('tok');

        self::assertSame(['101', '103'], array_map(static fn ($page): string => $page->id, $pages));
        self::assertSame('pt1', $pages[0]->accessToken);
        self::assertStringStartsWith('https://graph.facebook.com/v27.0/me/accounts?', $this->requests[0]['url']);
        parse_str((string) parse_url($this->requests[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame(hash_hmac('sha256', 'tok', 'meta-secret'), $query['appsecret_proof']);
    }

    public function testPhotoIsUploadedAsMultipartWithMessage(): void
    {
        $api = $this->api([
            new MockResponse('{"id":"photo1","post_id":"101_202"}'),
            new MockResponse('{"permalink_url":"https://www.facebook.com/101/posts/202","id":"101_202"}'),
        ]);

        self::assertSame('101_202', $api->publishPhoto('101', 'pt1', 'PNGBYTES', 'Ahoj'));
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://graph.facebook.com/v27.0/101/photos', $this->requests[0]['url']);
        self::assertStringContainsString('name="source"; filename="image.png"', $this->requests[0]['body']);
        self::assertStringContainsString('PNGBYTES', $this->requests[0]['body']);
        self::assertStringContainsString("name=\"message\"\r\n\r\nAhoj", $this->requests[0]['body']);
        self::assertStringContainsString(hash_hmac('sha256', 'pt1', 'meta-secret'), $this->requests[0]['body']);

        self::assertSame('https://www.facebook.com/101/posts/202', $api->fetchPermalink('101_202', 'pt1'));
    }

    public function testPhotoWithoutPostIdFallsBackToThePhotoId(): void
    {
        self::assertSame('photo1', $this->api([new MockResponse('{"id":"photo1"}')])->publishPhoto('101', 'pt1', 'X', ''));
        self::assertStringNotContainsString('name="message"', $this->requests[0]['body']);
    }

    public function testErrorsMapToActionableExceptions(): void
    {
        $error = static fn (int $code): MockResponse => new MockResponse(
            json_encode(['error' => ['message' => 'nope', 'type' => 'OAuthException', 'code' => $code]], JSON_THROW_ON_ERROR),
            ['http_code' => 400],
        );

        foreach ([190 => FacebookPageTokenExpired::class, 200 => FacebookPagePermissionMissing::class, 368 => FacebookPageRateLimited::class] as $code => $class) {
            try {
                $this->api([$error($code)])->checkPageToken('101', 'pt1');
                self::fail('expected ' . $class);
            } catch (\Throwable $exception) {
                self::assertInstanceOf($class, $exception);
            }
        }
    }
}
