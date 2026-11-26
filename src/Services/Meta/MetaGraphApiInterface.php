<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Meta;

use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Value\FacebookLongLivedToken;
use WBoost\Web\Value\FacebookPage;

/**
 * Thin wrapper over the Meta Graph API (graph.facebook.com) — the user's
 * personal Facebook link + publishing to their Facebook Pages. (Instagram
 * publishing is per project: InstagramApiInterface.) Faked in tests
 * via config/services_test.php — keep every outbound Graph call behind it.
 */
interface MetaGraphApiInterface
{
    /**
     * Exchange a short-lived user token for a ~60-day long-lived one.
     *
     * @throws MetaApiError
     */
    public function exchangeLongLivedUserToken(string $shortLivedToken): FacebookLongLivedToken;

    /**
     * Permissions the user actually granted (they can uncheck scopes in the
     * consent dialog).
     *
     * @return list<string>
     * @throws MetaApiError
     */
    public function fetchGrantedScopes(string $accessToken): array;

    /**
     * Facebook Pages the user manages, each with its Page access token and
     * linked Instagram professional account (if any). Follows paging.
     *
     * @return list<FacebookPage>
     * @throws MetaApiError
     */
    public function fetchAccounts(string $userAccessToken): array;

    /**
     * Publish a photo post on a Page (direct binary upload). Returns the
     * created post id.
     *
     * @throws MetaApiError
     */
    public function publishPagePhoto(string $pageId, string $pageAccessToken, string $imageBytes, string $caption): string;
}
