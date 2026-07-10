<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Fakes;

use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Services\Meta\MetaGraphApiInterface;
use WBoost\Web\Value\FacebookLongLivedToken;
use WBoost\Web\Value\FacebookPage;

/**
 * Deterministic Meta Graph API double: two canned pages (the second with a
 * linked Instagram account), recorded calls, and settable failures — the
 * FakeTemplateVariantImageRenderer pattern. Configure per-test via
 * `self::getContainer()->get(MetaGraphApiInterface::class)`.
 */
final class FakeMetaGraphApi implements MetaGraphApiInterface
{
    public const string PAGE_WITHOUT_IG_ID = 'fb-page-1';
    public const string PAGE_WITH_IG_ID = 'fb-page-2';
    public const string IG_USER_ID = 'ig-user-1';
    public const string PUBLISHED_POST_ID = 'fb-page-2_post-1';
    public const string PUBLISHED_MEDIA_ID = 'ig-media-1';

    /** @var list<array{method: string, args: array<string, mixed>}> */
    public array $calls = [];

    public null|MetaApiError $throwOnFetchAccounts = null;
    public null|MetaApiError $throwOnPublish = null;
    public string $containerStatus = 'FINISHED';

    public function exchangeLongLivedUserToken(string $shortLivedToken): FacebookLongLivedToken
    {
        $this->calls[] = ['method' => 'exchangeLongLivedUserToken', 'args' => []];

        return new FacebookLongLivedToken('long-lived-' . $shortLivedToken, time() + 5_184_000);
    }

    public function fetchGrantedScopes(string $accessToken): array
    {
        $this->calls[] = ['method' => 'fetchGrantedScopes', 'args' => []];

        return [
            'public_profile',
            'email',
            'pages_show_list',
            'pages_read_engagement',
            'pages_manage_posts',
            'instagram_basic',
            'instagram_content_publish',
        ];
    }

    public function fetchAccounts(string $userAccessToken): array
    {
        $this->calls[] = ['method' => 'fetchAccounts', 'args' => ['userAccessToken' => $userAccessToken]];

        if ($this->throwOnFetchAccounts !== null) {
            throw $this->throwOnFetchAccounts;
        }

        return [
            new FacebookPage(self::PAGE_WITHOUT_IG_ID, 'Page One', 'page-token-1', null, null),
            new FacebookPage(self::PAGE_WITH_IG_ID, 'Page Two', 'page-token-2', self::IG_USER_ID, 'brand.two'),
        ];
    }

    public function publishPagePhoto(string $pageId, string $pageAccessToken, string $imageBytes, string $caption): string
    {
        $this->calls[] = ['method' => 'publishPagePhoto', 'args' => [
            'pageId' => $pageId,
            'pageAccessToken' => $pageAccessToken,
            'imageBytesLength' => strlen($imageBytes),
            'caption' => $caption,
        ]];

        if ($this->throwOnPublish !== null) {
            throw $this->throwOnPublish;
        }

        return self::PUBLISHED_POST_ID;
    }

    public function createInstagramContainer(string $igUserId, string $accessToken, string $imageUrl, string $caption): string
    {
        $this->calls[] = ['method' => 'createInstagramContainer', 'args' => [
            'igUserId' => $igUserId,
            'imageUrl' => $imageUrl,
            'caption' => $caption,
        ]];

        if ($this->throwOnPublish !== null) {
            throw $this->throwOnPublish;
        }

        return 'ig-container-1';
    }

    public function getInstagramContainerStatus(string $containerId, string $accessToken): string
    {
        $this->calls[] = ['method' => 'getInstagramContainerStatus', 'args' => ['containerId' => $containerId]];

        return $this->containerStatus;
    }

    public function publishInstagramContainer(string $igUserId, string $accessToken, string $creationId): string
    {
        $this->calls[] = ['method' => 'publishInstagramContainer', 'args' => [
            'igUserId' => $igUserId,
            'creationId' => $creationId,
        ]];

        return self::PUBLISHED_MEDIA_ID;
    }

    /**
     * @return list<array{method: string, args: array<string, mixed>}>
     */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method));
    }
}
