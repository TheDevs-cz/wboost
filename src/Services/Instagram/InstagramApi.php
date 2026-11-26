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
 * "Instagram API with Instagram Login". Three hosts:
 *  - www.instagram.com/oauth/authorize — the consent screen,
 *  - api.instagram.com/oauth/access_token — code → short-lived token,
 *  - graph.instagram.com — long-lived token, refresh, profile, publishing
 *    (versioned base URL in `INSTAGRAM_GRAPH_BASE_URL`; the token endpoints
 *    are unversioned, so they are called by absolute URL).
 *
 * Meta's docs show some responses both flat and wrapped in `data: [ … ]`;
 * every reader here accepts both.
 */
readonly final class InstagramApi implements InstagramApiInterface
{
    private const string AUTHORIZE_URL = 'https://www.instagram.com/oauth/authorize';
    private const string CODE_EXCHANGE_URL = 'https://api.instagram.com/oauth/access_token';
    private const string LONG_LIVED_URL = 'https://graph.instagram.com/access_token';
    private const string REFRESH_URL = 'https://graph.instagram.com/refresh_access_token';

    public function __construct(
        #[Autowire(service: 'instagram.client')]
        private HttpClientInterface $client,
        #[Autowire('%env(INSTAGRAM_APP_ID)%')]
        private string $appId,
        #[Autowire('%env(INSTAGRAM_APP_SECRET)%')]
        #[SensitiveParameter]
        private string $appSecret,
    ) {
    }

    public function authorizationUrl(string $redirectUri, string $state, array $scopes): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => $this->appId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(',', $scopes),
            'state' => $state,
            // An agency person logged into one Instagram account must not
            // silently attach that same account to every project.
            'force_reauth' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri): InstagramToken
    {
        $data = self::unwrap($this->request('POST', self::CODE_EXCHANGE_URL, [
            'body' => [
                'client_id' => $this->appId,
                'client_secret' => $this->appSecret,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
                // The redirect appends `#_`; browsers drop fragments, but a
                // pasted code may still carry it.
                'code' => preg_replace('/#_$/', '', $code),
            ],
        ]));

        $token = self::stringValue($data, 'access_token');

        if ($token === null) {
            throw new MetaApiError('Instagram code exchange response is missing access_token.');
        }

        $permissions = $data['permissions'] ?? [];
        $permissions = is_string($permissions) ? explode(',', $permissions) : (is_array($permissions) ? $permissions : []);

        return new InstagramToken(
            $token,
            time() + 3600,
            self::scalarString($data, 'user_id'),
            array_values(array_filter(array_map(
                static fn (mixed $permission): string => is_string($permission) ? trim($permission) : '',
                $permissions,
            ), static fn (string $permission): bool => $permission !== '')),
        );
    }

    public function exchangeLongLivedToken(string $shortLivedToken): InstagramToken
    {
        return $this->longLived($this->request('GET', self::LONG_LIVED_URL, [
            'query' => [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => $this->appSecret,
                'access_token' => $shortLivedToken,
            ],
        ]));
    }

    public function refreshToken(string $longLivedToken): InstagramToken
    {
        return $this->longLived($this->request('GET', self::REFRESH_URL, [
            'query' => [
                'grant_type' => 'ig_refresh_token',
                'access_token' => $longLivedToken,
            ],
        ]));
    }

    public function fetchProfile(string $accessToken): InstagramProfile
    {
        $data = self::unwrap($this->request('GET', 'me', [
            'query' => [
                'fields' => 'id,user_id,username,name,account_type',
                'access_token' => $accessToken,
            ],
        ]));

        $userId = self::scalarString($data, 'user_id') ?? self::scalarString($data, 'id');
        $username = self::stringValue($data, 'username');

        if ($userId === null || $username === null) {
            throw new MetaApiError('Instagram profile response is missing user_id / username.');
        }

        return new InstagramProfile(
            $userId,
            self::scalarString($data, 'id'),
            $username,
            self::stringValue($data, 'name'),
            self::stringValue($data, 'account_type'),
        );
    }

    public function createImageContainer(string $igUserId, string $accessToken, string $imageUrl, null|string $caption, bool $story): string
    {
        $body = [
            'image_url' => $imageUrl,
            'access_token' => $accessToken,
        ];

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
            'query' => [
                'fields' => 'status_code',
                'access_token' => $accessToken,
            ],
        ]));

        return self::stringValue($data, 'status_code') ?? 'IN_PROGRESS';
    }

    public function publishContainer(string $igUserId, string $accessToken, string $containerId): string
    {
        $mediaId = self::scalarString(self::unwrap($this->request('POST', $igUserId . '/media_publish', [
            'body' => [
                'creation_id' => $containerId,
                'access_token' => $accessToken,
            ],
        ])), 'id');

        if ($mediaId === null) {
            throw new MetaApiError('Instagram publish response is missing an id.');
        }

        return $mediaId;
    }

    public function fetchPermalink(string $mediaId, string $accessToken): null|string
    {
        $data = self::unwrap($this->request('GET', $mediaId, [
            'query' => [
                'fields' => 'permalink',
                'access_token' => $accessToken,
            ],
        ]));

        return self::stringValue($data, 'permalink');
    }

    /**
     * @param array<mixed> $data
     * @throws MetaApiError
     */
    private function longLived(array $data): InstagramToken
    {
        $data = self::unwrap($data);
        $token = self::stringValue($data, 'access_token');

        if ($token === null) {
            throw new MetaApiError('Instagram token response is missing access_token.');
        }

        $expiresIn = $data['expires_in'] ?? null;

        return new InstagramToken(
            $token,
            is_int($expiresIn) && $expiresIn > 0 ? time() + $expiresIn : null,
        );
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
     * api.instagram.com token endpoint: {"error_type", "code", "error_message"}.
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
