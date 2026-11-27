<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Fakes;

use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Services\FacebookPage\FacebookPageApiInterface;
use WBoost\Web\Value\FacebookLongLivedToken;
use WBoost\Web\Value\FacebookPage;

/**
 * Deterministic project-Facebook-Page double: canned token + shared Pages,
 * recorded calls, settable failures.
 */
final class FakeFacebookPageApi implements FacebookPageApiInterface
{
    public const string PAGE_ID = '100000000000001';
    public const string PAGE_NAME = 'Kavárna Test';
    public const string PAGE_TOKEN = 'page-token-1';
    public const string OWNER_ID = '9100000000000001';
    public const string POST_ID = '100000000000001_200000000000001';
    public const string PERMALINK = 'https://www.facebook.com/kavarnatest/posts/200000000000001';

    /** @var list<array{method: string, args: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<FacebookPage> */
    public array $pages;

    public null|MetaApiError $throwOnExchange = null;
    public null|MetaApiError $throwOnPublish = null;
    public null|MetaApiError $throwOnCheck = null;
    public null|string $permalink = self::PERMALINK;

    public function __construct()
    {
        $this->pages = [new FacebookPage(self::PAGE_ID, self::PAGE_NAME, self::PAGE_TOKEN, null, null)];
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return 'https://www.facebook.com/dialog/oauth?' . http_build_query([
            'config_id' => 'test-fb-pages-config-id',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): FacebookLongLivedToken
    {
        $this->calls[] = ['method' => 'exchangeCode', 'args' => ['code' => $code, 'redirectUri' => $redirectUri]];

        if ($this->throwOnExchange !== null) {
            throw $this->throwOnExchange;
        }

        return new FacebookLongLivedToken('bisu-' . $code, null);
    }

    public null|string $ownerId = self::OWNER_ID;

    public function tokenOwnerId(string $accessToken): null|string
    {
        return $this->ownerId;
    }

    public function pages(string $accessToken): array
    {
        $this->calls[] = ['method' => 'pages', 'args' => ['accessToken' => $accessToken]];

        return $this->pages;
    }

    public function checkPageToken(string $pageId, string $pageAccessToken): void
    {
        $this->calls[] = ['method' => 'checkPageToken', 'args' => ['pageId' => $pageId, 'pageAccessToken' => $pageAccessToken]];

        if ($this->throwOnCheck !== null) {
            throw $this->throwOnCheck;
        }
    }

    public function publishPhoto(string $pageId, string $pageAccessToken, string $imageBytes, string $caption): string
    {
        $this->calls[] = ['method' => 'publishPhoto', 'args' => [
            'pageId' => $pageId,
            'pageAccessToken' => $pageAccessToken,
            'bytes' => strlen($imageBytes),
            'caption' => $caption,
        ]];

        if ($this->throwOnPublish !== null) {
            throw $this->throwOnPublish;
        }

        return self::POST_ID;
    }

    public function fetchPermalink(string $postId, string $pageAccessToken): null|string
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
