<?php

declare(strict_types=1);

namespace WBoost\Web\Services\FacebookPage;

use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Value\FacebookLongLivedToken;
use WBoost\Web\Value\FacebookPage;

/**
 * Project Facebook Page publishing through Facebook Login for Business (the
 * SAME Meta app as Instagram, its own configuration
 * `FACEBOOK_PAGES_LOGIN_CONFIG_ID`). Faked in tests via
 * config/services_test.php — keep every outbound call behind it.
 */
interface FacebookPageApiInterface
{
    /**
     * The Login URL (www.facebook.com/dialog/oauth with the Pages config id).
     */
    public function authorizationUrl(string $redirectUri, string $state): string;

    /**
     * Authorization code → Business Integration System User access token.
     *
     * @throws MetaApiError
     */
    public function exchangeCode(string $code, string $redirectUri): FacebookLongLivedToken;

    /**
     * The token owner's id (`GET /me` — the app-scoped system user).
     *
     * @throws MetaApiError
     */
    public function tokenOwnerId(string $accessToken): null|string;

    /**
     * The Pages the client shared on which content can be created
     * (`GET /me/accounts`), each with its Page access token.
     *
     * @return list<FacebookPage>
     * @throws MetaApiError
     */
    public function pages(string $accessToken): array;

    /**
     * A cheap call with the Page token (`GET /{page-id}?fields=id`) — throws
     * FacebookPageTokenExpired when Meta no longer accepts it.
     *
     * @throws MetaApiError
     */
    public function checkPageToken(string $pageId, string $pageAccessToken): void;

    /**
     * A photo post on the Page (multipart upload — no public URL needed).
     * Returns the post id (`post_id`, falling back to the photo id).
     *
     * @throws MetaApiError
     */
    public function publishPhoto(string $pageId, string $pageAccessToken, string $imageBytes, string $caption): string;

    /**
     * The post's public link, null when Facebook gives none.
     *
     * @throws MetaApiError
     */
    public function fetchPermalink(string $postId, string $pageAccessToken): null|string;
}
