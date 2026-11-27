<?php

declare(strict_types=1);

namespace WBoost\Web\Services\FacebookPage;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use WBoost\Web\Exceptions\FacebookPagePermissionMissing;
use WBoost\Web\Exceptions\FacebookPageRateLimited;
use WBoost\Web\Exceptions\FacebookPageTokenExpired;
use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Value\FacebookLongLivedToken;
use WBoost\Web\Value\FacebookPage;

/**
 * Facebook Login for Business with a System-user access token configuration
 * for Pages (`FACEBOOK_PAGES_LOGIN_CONFIG_ID`), on the Meta app that also
 * hosts Instagram (`INSTAGRAM_META_APP_ID` / `_SECRET`). graph.facebook.com
 * through the same versioned `instagram.client`. Every token-bearing call
 * carries `appsecret_proof`.
 */
readonly final class FacebookPageApi implements FacebookPageApiInterface
{
    private const string LOGIN_URL = 'https://www.facebook.com/dialog/oauth';

    public function __construct(
        #[Autowire(service: 'instagram.client')]
        private HttpClientInterface $client,
        #[Autowire('%env(INSTAGRAM_META_APP_ID)%')]
        private string $appId,
        #[Autowire('%env(INSTAGRAM_META_APP_SECRET)%')]
        #[SensitiveParameter]
        private string $appSecret,
        #[Autowire('%env(FACEBOOK_PAGES_LOGIN_CONFIG_ID)%')]
        private string $configId,
    ) {
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return self::LOGIN_URL . '?' . http_build_query([
            'client_id' => $this->appId,
            'config_id' => $this->configId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'override_default_response_type' => 'true',
            'state' => $state,
            'prompt' => 'login',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri): FacebookLongLivedToken
    {
        $data = $this->request('GET', 'oauth/access_token', [
            'query' => [
                'client_id' => $this->appId,
                'client_secret' => $this->appSecret,
                'redirect_uri' => $redirectUri,
                'code' => preg_replace('/#_$/', '', $code),
            ],
        ]);

        $token = self::stringValue($data, 'access_token');

        if ($token === null) {
            throw new MetaApiError('Facebook code exchange response is missing access_token.');
        }

        $expiresIn = $data['expires_in'] ?? null;

        return new FacebookLongLivedToken($token, is_int($expiresIn) && $expiresIn > 0 ? time() + $expiresIn : null);
    }

    public function tokenOwnerId(string $accessToken): null|string
    {
        return self::scalarString($this->request('GET', 'me', [
            'query' => ['fields' => 'id'] + $this->auth($accessToken),
        ]), 'id');
    }

    public function pages(string $accessToken): array
    {
        $data = $this->request('GET', 'me/accounts', [
            'query' => ['fields' => 'id,name,access_token,tasks', 'limit' => 100] + $this->auth($accessToken),
        ]);

        $rows = $data['data'] ?? [];
        $pages = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = self::scalarString($row, 'id');
            $name = self::stringValue($row, 'name');
            $pageToken = self::stringValue($row, 'access_token');
            $tasks = $row['tasks'] ?? null;

            // No `tasks` in the answer = can't tell; a list without
            // CREATE_CONTENT = this person / system user can't post there.
            if ($id === null || $name === null || $pageToken === null || (is_array($tasks) && !in_array('CREATE_CONTENT', $tasks, true))) {
                continue;
            }

            $pages[] = new FacebookPage($id, $name, $pageToken, null, null);
        }

        return $pages;
    }

    public function checkPageToken(string $pageId, string $pageAccessToken): void
    {
        $this->request('GET', $pageId, ['query' => ['fields' => 'id'] + $this->auth($pageAccessToken)]);
    }

    public function publishPhoto(string $pageId, string $pageAccessToken, string $imageBytes, string $caption): string
    {
        $fields = [
            'source' => new DataPart($imageBytes, 'image.png', 'image/png'),
            'published' => 'true',
        ] + $this->auth($pageAccessToken);

        if ($caption !== '') {
            $fields['message'] = $caption;
        }

        $form = new FormDataPart($fields);
        $data = $this->request('POST', $pageId . '/photos', [
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body' => $form->bodyToIterable(),
        ]);

        $postId = self::scalarString($data, 'post_id') ?? self::scalarString($data, 'id');

        if ($postId === null) {
            throw new MetaApiError('Facebook photo response is missing an id.');
        }

        return $postId;
    }

    public function fetchPermalink(string $postId, string $pageAccessToken): null|string
    {
        return self::stringValue($this->request('GET', $postId, [
            'query' => ['fields' => 'permalink_url'] + $this->auth($pageAccessToken),
        ]), 'permalink_url');
    }

    /**
     * @return array{access_token: string, appsecret_proof: string}
     */
    private function auth(string $accessToken): array
    {
        return [
            'access_token' => $accessToken,
            'appsecret_proof' => hash_hmac('sha256', $accessToken, $this->appSecret),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<mixed>
     * @throws MetaApiError
     */
    private function request(string $method, string $url, array $options = []): array
    {
        try {
            return $this->client->request($method, $url, $options)->toArray();
        } catch (HttpExceptionInterface $exception) {
            throw self::mapError($exception);
        } catch (ExceptionInterface $exception) {
            throw new MetaApiError('Facebook API request failed: ' . $exception->getMessage(), previous: $exception);
        }
    }

    /**
     * 190 = invalid token, 10 + 200-299 = permission, 4/17/32/613/368 =
     * rate limit / temporarily blocked.
     */
    private static function mapError(HttpExceptionInterface $exception): MetaApiError
    {
        $message = $exception->getMessage();
        $code = null;
        $subcode = null;

        try {
            $error = $exception->getResponse()->toArray(false)['error'] ?? null;

            if (is_array($error)) {
                $message = self::stringValue($error, 'message') ?? $message;
                $code = is_int($error['code'] ?? null) ? $error['code'] : null;
                $subcode = is_int($error['error_subcode'] ?? null) ? $error['error_subcode'] : null;
            }
        } catch (\Throwable) {
            // Non-JSON error body — keep the transport message.
        }

        if ($code === 190) {
            return new FacebookPageTokenExpired($message, $code, $subcode, $exception);
        }

        if ($code === 10 || ($code !== null && $code >= 200 && $code < 300)) {
            return new FacebookPagePermissionMissing($message, $code, $subcode, $exception);
        }

        if (in_array($code, [4, 17, 32, 368, 613], true)) {
            return new FacebookPageRateLimited($message, $code, $subcode, $exception);
        }

        return new MetaApiError($message, $code, $subcode, $exception);
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringValue(array $data, string $key): null|string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<mixed> $data
     */
    private static function scalarString(array $data, string $key): null|string
    {
        $value = $data[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            return number_format($value, 0, '', '');
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
