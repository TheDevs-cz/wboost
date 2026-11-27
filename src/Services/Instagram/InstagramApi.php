<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Instagram;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use WBoost\Web\Exceptions\InstagramPermissionMissing;
use WBoost\Web\Exceptions\InstagramRateLimited;
use WBoost\Web\Exceptions\InstagramTokenExpired;
use WBoost\Web\Exceptions\InstagramUnsupportedImage;
use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Value\InstagramProfile;
use WBoost\Web\Value\InstagramToken;

/**
 * "Instagram API with Unified Login" (Meta, 2026-11: newly created apps can
 * no longer set up the older Instagram Login). Two hosts:
 *  - www.facebook.com/dialog/oauth — the Login URL (Facebook Login for
 *    Business with the Unified Login `config_id`; permissions come from the
 *    configuration, never a `scope` parameter),
 *  - graph.facebook.com (versioned base URL in `INSTAGRAM_GRAPH_BASE_URL`) —
 *    code exchange, the shared accounts, publishing.
 *
 * Credentials are the META app id / secret (App settings → Basic), not the
 * Instagram app id / secret. Every call that carries a token also carries
 * `appsecret_proof` (works whether or not "Require app secret" is on).
 *
 * Meta's docs show some responses both flat and wrapped in `data: [ … ]`;
 * the single-object readers here accept both.
 */
readonly final class InstagramApi implements InstagramApiInterface
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
        #[Autowire('%env(INSTAGRAM_LOGIN_CONFIG_ID)%')]
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
            'state' => $state,
            // Someone already logged in to Facebook would otherwise continue
            // as that account — an agency person must pick deliberately.
            'prompt' => 'login',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri): InstagramToken
    {
        $data = self::unwrap($this->request('GET', 'oauth/access_token', [
            'query' => [
                'client_id' => $this->appId,
                'client_secret' => $this->appSecret,
                'redirect_uri' => $redirectUri,
                // A pasted code may still carry the `#_` fragment.
                'code' => preg_replace('/#_$/', '', $code),
            ],
        ]));

        $token = self::stringValue($data, 'access_token');

        if ($token === null) {
            throw new MetaApiError('Instagram code exchange response is missing access_token.');
        }

        // No `expires_in` (or 0) = a token that never expires.
        $expiresIn = $data['expires_in'] ?? null;

        return new InstagramToken(
            $token,
            is_int($expiresIn) && $expiresIn > 0 ? time() + $expiresIn : null,
        );
    }

    public function tokenOwnerId(string $accessToken): null|string
    {
        $data = self::unwrap($this->request('GET', 'me', [
            'query' => ['fields' => 'id'] + $this->auth($accessToken),
        ]));

        return self::scalarString($data, 'id');
    }

    public function assignedAccounts(string $accessToken): array
    {
        $data = $this->request('GET', 'me/assigned_instagram_accounts', [
            'query' => ['fields' => 'id,username,name'] + $this->auth($accessToken),
        ]);

        $rows = $data['data'] ?? [];
        $accounts = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = self::scalarString($row, 'id');
            $username = self::stringValue($row, 'username');

            if ($id !== null && $username !== null) {
                $accounts[] = new InstagramProfile($id, $username, self::stringValue($row, 'name'));
            }
        }

        return $accounts;
    }

    public function createImageContainer(string $igUserId, string $accessToken, string $imageUrl, null|string $caption, bool $story): string
    {
        $body = ['image_url' => $imageUrl] + $this->auth($accessToken);

        if ($story) {
            $body['media_type'] = 'STORIES';
        } elseif ($caption !== null && $caption !== '') {
            $body['caption'] = $caption;
        }

        $containerId = self::scalarString(self::unwrap($this->request('POST', $igUserId . '/media', ['body' => $body])), 'id');

        if ($containerId === null) {
            throw new MetaApiError('Instagram container response is missing an id.');
        }

        return $containerId;
    }

    public function containerStatus(string $containerId, string $accessToken): string
    {
        $data = self::unwrap($this->request('GET', $containerId, [
            'query' => ['fields' => 'status_code'] + $this->auth($accessToken),
        ]));

        return self::stringValue($data, 'status_code') ?? 'IN_PROGRESS';
    }

    public function publishContainer(string $igUserId, string $accessToken, string $containerId): string
    {
        $mediaId = self::scalarString(self::unwrap($this->request('POST', $igUserId . '/media_publish', [
            'body' => ['creation_id' => $containerId] + $this->auth($accessToken),
        ])), 'id');

        if ($mediaId === null) {
            throw new MetaApiError('Instagram publish response is missing an id.');
        }

        return $mediaId;
    }

    public function fetchPermalink(string $mediaId, string $accessToken): null|string
    {
        $data = self::unwrap($this->request('GET', $mediaId, [
            'query' => ['fields' => 'permalink'] + $this->auth($accessToken),
        ]));

        return self::stringValue($data, 'permalink');
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
            throw new MetaApiError('Instagram API request failed: ' . $exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Graph errors: {"error": {message, type, code, error_subcode}}; the
     * older Instagram token endpoint shape {"error_type", "code",
     * "error_message"} is still read, just in case.
     * 190 = invalid/expired token, 10 + 200-299 = permission, 4/17/32/613
     * (+ subcode 2207042) = rate limit, 36003 / 2207009 = unsupported ratio.
     */
    private static function mapError(HttpExceptionInterface $exception): MetaApiError
    {
        $message = $exception->getMessage();
        $code = null;
        $subcode = null;

        try {
            $body = $exception->getResponse()->toArray(false);
            $error = $body['error'] ?? null;

            if (is_array($error)) {
                $message = self::stringValue($error, 'message') ?? $message;
                $code = is_int($error['code'] ?? null) ? $error['code'] : null;
                $subcode = is_int($error['error_subcode'] ?? null) ? $error['error_subcode'] : null;
            } elseif (isset($body['error_message'])) {
                $message = self::stringValue($body, 'error_message') ?? $message;
                $code = is_int($body['code'] ?? null) ? $body['code'] : null;
            }
        } catch (\Throwable) {
            // Non-JSON error body — keep the transport message.
        }

        if ($code === 190) {
            return new InstagramTokenExpired($message, $code, $subcode, $exception);
        }

        if ($code === 10 || ($code !== null && $code >= 200 && $code < 300)) {
            return new InstagramPermissionMissing($message, $code, $subcode, $exception);
        }

        if (in_array($code, [4, 17, 32, 613], true) || $subcode === 2207042) {
            return new InstagramRateLimited($message, $code, $subcode, $exception);
        }

        if ($code === 36003 || $subcode === 2207009) {
            return new InstagramUnsupportedImage($message, $code, $subcode, $exception);
        }

        return new MetaApiError($message, $code, $subcode, $exception);
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function unwrap(array $data): array
    {
        $inner = $data['data'] ?? null;

        if (is_array($inner) && array_is_list($inner) && isset($inner[0]) && is_array($inner[0])) {
            return $inner[0];
        }

        return $data;
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
     * Ids come as strings or (from the token endpoint) as numbers.
     *
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
