<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Fakes;

use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Services\Instagram\InstagramApiInterface;
use WBoost\Web\Value\InstagramProfile;
use WBoost\Web\Value\InstagramToken;

/**
 * Deterministic Unified Login double: canned token + shared accounts,
 * recorded calls, settable failures. Configure per test via
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

    /** @var list<InstagramProfile> */
    public array $accounts;

    public null|MetaApiError $throwOnExchange = null;
    public null|MetaApiError $throwOnPublish = null;
    public null|MetaApiError $throwOnAccounts = null;
    public string $containerStatus = 'FINISHED';
    public null|string $permalink = self::PERMALINK;

    public function __construct()
    {
        $this->accounts = [new InstagramProfile(self::IG_USER_ID, self::USERNAME, 'Kavárna Test')];
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return 'https://www.facebook.com/dialog/oauth?' . http_build_query([
            'config_id' => 'test-ig-config-id',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): InstagramToken
    {
        $this->calls[] = ['method' => 'exchangeCode', 'args' => ['code' => $code, 'redirectUri' => $redirectUri]];

        if ($this->throwOnExchange !== null) {
            throw $this->throwOnExchange;
        }

        return new InstagramToken('bisu-' . $code, null);
    }

    public null|string $ownerId = self::APP_SCOPED_ID;

    public function tokenOwnerId(string $accessToken): null|string
    {
        $this->calls[] = ['method' => 'tokenOwnerId', 'args' => []];

        return $this->ownerId;
    }

    public function assignedAccounts(string $accessToken): array
    {
        $this->calls[] = ['method' => 'assignedAccounts', 'args' => ['accessToken' => $accessToken]];

        if ($this->throwOnAccounts !== null) {
            throw $this->throwOnAccounts;
        }

        return $this->accounts;
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
