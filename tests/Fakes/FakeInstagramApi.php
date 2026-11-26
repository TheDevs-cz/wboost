<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Fakes;

use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Services\Instagram\InstagramApiInterface;
use WBoost\Web\Value\InstagramProfile;
use WBoost\Web\Value\InstagramToken;

/**
 * Deterministic Instagram Login double: canned tokens + profile, recorded
 * calls, settable failures. Configure per test via
 * `self::getContainer()->get(InstagramApiInterface::class)`.
 */
final class FakeInstagramApi implements InstagramApiInterface
{
    public const string IG_USER_ID = '17841400000000001';
    public const string APP_SCOPED_ID = '9000000000000001';
    public const string USERNAME = 'kavarna_test';
    public const string MEDIA_ID = '18000000000000001';
    public const string PERMALINK = 'https://www.instagram.com/p/TEST123/';

    /** @var list<array{method: string, args: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<string> */
    public array $grantedPermissions = ['instagram_business_basic', 'instagram_business_content_publish'];

    public null|MetaApiError $throwOnExchange = null;
    public null|MetaApiError $throwOnPublish = null;
    public null|MetaApiError $throwOnRefresh = null;
    public string $containerStatus = 'FINISHED';

    public function authorizationUrl(string $redirectUri, string $state, array $scopes): string
    {
        return 'https://www.instagram.com/oauth/authorize?' . http_build_query([
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(',', $scopes),
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): InstagramToken
    {
        $this->calls[] = ['method' => 'exchangeCode', 'args' => ['code' => $code, 'redirectUri' => $redirectUri]];

        if ($this->throwOnExchange !== null) {
            throw $this->throwOnExchange;
        }

        return new InstagramToken('short-' . $code, time() + 3600, self::IG_USER_ID, $this->grantedPermissions);
    }

    public function exchangeLongLivedToken(string $shortLivedToken): InstagramToken
    {
        $this->calls[] = ['method' => 'exchangeLongLivedToken', 'args' => []];

        return new InstagramToken('long-' . $shortLivedToken, time() + 5_184_000);
    }

    public function refreshToken(string $longLivedToken): InstagramToken
    {
        $this->calls[] = ['method' => 'refreshToken', 'args' => ['token' => $longLivedToken]];

        if ($this->throwOnRefresh !== null) {
            throw $this->throwOnRefresh;
        }

        return new InstagramToken('refreshed-' . $longLivedToken, time() + 5_184_000);
    }

    public function fetchProfile(string $accessToken): InstagramProfile
    {
        $this->calls[] = ['method' => 'fetchProfile', 'args' => []];

        return new InstagramProfile(self::IG_USER_ID, self::APP_SCOPED_ID, self::USERNAME, 'Kavárna Test', 'BUSINESS');
    }

    public function createImageContainer(string $igUserId, string $accessToken, string $imageUrl, null|string $caption, bool $story): string
    {
        $this->calls[] = ['method' => 'createImageContainer', 'args' => [
            'igUserId' => $igUserId,
            'accessToken' => $accessToken,
            'imageUrl' => $imageUrl,
            'caption' => $caption,
            'story' => $story,
        ]];

        if ($this->throwOnPublish !== null) {
            throw $this->throwOnPublish;
        }

        return 'ig-container-1';
    }

    public function containerStatus(string $containerId, string $accessToken): string
    {
        $this->calls[] = ['method' => 'containerStatus', 'args' => ['containerId' => $containerId]];

        return $this->containerStatus;
    }

    public function publishContainer(string $igUserId, string $accessToken, string $containerId): string
    {
        $this->calls[] = ['method' => 'publishContainer', 'args' => ['igUserId' => $igUserId, 'containerId' => $containerId]];

        return self::MEDIA_ID;
    }

    public null|string $permalink = self::PERMALINK;

    public function fetchPermalink(string $mediaId, string $accessToken): null|string
    {
        return $this->permalink;
    }

    /**
     * @return list<array{method: string, args: array<string, mixed>}>
     */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method));
    }
}
