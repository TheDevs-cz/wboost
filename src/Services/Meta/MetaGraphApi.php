<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Meta;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use WBoost\Web\Exceptions\FacebookPermissionMissing;
use WBoost\Web\Exceptions\FacebookTokenExpired;
use WBoost\Web\Exceptions\InstagramRateLimited;
use WBoost\Web\Exceptions\InstagramUnsupportedImage;
use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Value\FacebookLongLivedToken;
use WBoost\Web\Value\FacebookPage;

readonly final class MetaGraphApi implements MetaGraphApiInterface
{
    public function __construct(
        #[Autowire(service: 'meta.client')]
        private HttpClientInterface $client,
        #[Autowire('%env(FACEBOOK_APP_ID)%')]
        private string $appId,
        #[Autowire('%env(FACEBOOK_APP_SECRET)%')]
        #[SensitiveParameter]
        private string $appSecret,
    ) {
    }

    public function exchangeLongLivedUserToken(string $shortLivedToken): FacebookLongLivedToken
    {
        $data = $this->request('GET', 'oauth/access_token', [
            'query' => [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $this->appId,
                'client_secret' => $this->appSecret,
                'fb_exchange_token' => $shortLivedToken,
            ],
        ]);

        $token = self::stringValue($data, 'access_token');

        if ($token === null) {
            throw new MetaApiError('Token exchange response is missing access_token.');
        }

        $expiresIn = $data['expires_in'] ?? null;

        return new FacebookLongLivedToken(
            $token,
            is_int($expiresIn) && $expiresIn > 0 ? time() + $expiresIn : null,
        );
    }

    public function fetchGrantedScopes(string $accessToken): array
    {
        $data = $this->request('GET', 'me/permissions', [
            'query' => ['access_token' => $accessToken],
        ]);

        $scopes = [];

        foreach (self::listValue($data, 'data') as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (self::stringValue($row, 'status') === 'granted' && self::stringValue($row, 'permission') !== null) {
                $scopes[] = self::stringValue($row, 'permission');
            }
        }

        return array_values(array_filter($scopes, is_string(...)));
    }

    public function fetchAccounts(string $userAccessToken): array
    {
        $pages = [];
        $url = 'me/accounts';
        $query = [
            'fields' => 'id,name,access_token,instagram_business_account{id,username}',
            'limit' => 100,
            'access_token' => $userAccessToken,
        ];

        // Follow Graph API cursor paging; the `next` link is absolute (it
        // overrides the scoped client's base_uri) and already carries the
        // query string.
        while (true) {
            $data = $this->request('GET', $url, $query === [] ? [] : ['query' => $query]);

            foreach (self::listValue($data, 'data') as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $id = self::stringValue($row, 'id');
                $name = self::stringValue($row, 'name');
                $pageToken = self::stringValue($row, 'access_token');

                if ($id === null || $name === null || $pageToken === null) {
                    continue;
                }

                $instagram = $row['instagram_business_account'] ?? null;
                $instagram = is_array($instagram) ? $instagram : [];

                $pages[] = new FacebookPage(
                    $id,
                    $name,
                    $pageToken,
                    self::stringValue($instagram, 'id'),
                    self::stringValue($instagram, 'username'),
                );
            }

            $paging = $data['paging'] ?? null;
            $next = is_array($paging) ? self::stringValue($paging, 'next') : null;

            if ($next === null) {
                return $pages;
            }

            $url = $next;
            $query = [];
        }
    }

    public function publishPagePhoto(string $pageId, string $pageAccessToken, string $imageBytes, string $caption): string
    {
        $formData = new FormDataPart([
            'caption' => $caption,
            'access_token' => $pageAccessToken,
            'source' => new DataPart($imageBytes, 'wboost-export.png', 'image/png'),
        ]);

        $data = $this->request('POST', $pageId . '/photos', [
            'headers' => $formData->getPreparedHeaders()->toArray(),
            'body' => $formData->bodyToIterable(),
        ]);

        // `post_id` is the feed post the photo created; the bare `id` is the
        // photo node — prefer the post.
        $postId = self::stringValue($data, 'post_id') ?? self::stringValue($data, 'id');

        if ($postId === null) {
            throw new MetaApiError('Page photo publish response is missing an id.');
        }

        return $postId;
    }

    public function createInstagramContainer(string $igUserId, string $accessToken, string $imageUrl, string $caption): string
    {
        $data = $this->request('POST', $igUserId . '/media', [
            'body' => [
                'image_url' => $imageUrl,
                'caption' => $caption,
                'access_token' => $accessToken,
            ],
        ]);

        $containerId = self::stringValue($data, 'id');

        if ($containerId === null) {
            throw new MetaApiError('Instagram container response is missing an id.');
        }

        return $containerId;
    }

    public function getInstagramContainerStatus(string $containerId, string $accessToken): string
    {
        $data = $this->request('GET', $containerId, [
            'query' => [
                'fields' => 'status_code',
                'access_token' => $accessToken,
            ],
        ]);

        return self::stringValue($data, 'status_code') ?? 'IN_PROGRESS';
    }

    public function publishInstagramContainer(string $igUserId, string $accessToken, string $creationId): string
    {
        $data = $this->request('POST', $igUserId . '/media_publish', [
            'body' => [
                'creation_id' => $creationId,
                'access_token' => $accessToken,
            ],
        ]);

        $mediaId = self::stringValue($data, 'id');

        if ($mediaId === null) {
            throw new MetaApiError('Instagram publish response is missing an id.');
        }

        return $mediaId;
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
            throw $this->mapGraphError($exception);
        } catch (ExceptionInterface $exception) {
            throw new MetaApiError('Meta API request failed: ' . $exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Graph errors come as {"error": {message, type, code, error_subcode}}.
     * Map the documented codes to typed exceptions; see the error reference:
     * 190 = invalid/expired token, 10 + 200-299 + 803 = permission problems,
     * 4/17/32/613 (+ subcode 2207042) = rate limits.
     */
    private function mapGraphError(HttpExceptionInterface $exception): MetaApiError
    {
        $message = $exception->getMessage();
        $code = null;
        $subcode = null;

        try {
            $body = $exception->getResponse()->toArray(false);
            $error = $body['error'] ?? null;

            if (is_array($error)) {
                $graphMessage = self::stringValue($error, 'message');
                $message = $graphMessage ?? $message;
                $code = is_int($error['code'] ?? null) ? $error['code'] : null;
                $subcode = is_int($error['error_subcode'] ?? null) ? $error['error_subcode'] : null;
            }
        } catch (\Throwable) {
            // Non-JSON error body — keep the transport message.
        }

        if ($code === 190) {
            return new FacebookTokenExpired($message, $code, $subcode, $exception);
        }

        if ($code === 10 || $code === 803 || ($code !== null && $code >= 200 && $code < 300)) {
            return new FacebookPermissionMissing($message, $code, $subcode, $exception);
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
     */
    private static function stringValue(array $data, string $key): null|string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<mixed> $data
     * @return list<mixed>
     */
    private static function listValue(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? array_values($value) : [];
    }
}
