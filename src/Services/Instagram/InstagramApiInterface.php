<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Instagram;

use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Value\InstagramProfile;
use WBoost\Web\Value\InstagramToken;

/**
 * Thin wrapper over "Instagram API with Unified Login" (Facebook Login for
 * Business with a `config_id` + graph.facebook.com). Faked in tests via
 * config/services_test.php — keep every outbound Instagram call behind it.
 */
interface InstagramApiInterface
{
    /**
     * The Login URL people are sent to (www.facebook.com/dialog/oauth with
     * the Unified Login configuration id).
     */
    public function authorizationUrl(string $redirectUri, string $state): string;

    /**
     * Authorization code → Business Integration System User access token
     * (belongs to the client's business portfolio, not to the person).
     *
     * @throws MetaApiError
     */
    public function exchangeCode(string $code, string $redirectUri): InstagramToken;

    /**
     * The id of the token's owner (`GET /me` — the app-scoped system user).
     *
     * @throws MetaApiError
     */
    public function tokenOwnerId(string $accessToken): null|string;

    /**
     * The Instagram professional accounts the client shared with the app
     * (`GET /me/assigned_instagram_accounts`). Their `id` is what publishing
     * addresses.
     *
     * @return list<InstagramProfile>
     * @throws MetaApiError
     */
    public function assignedAccounts(string $accessToken): array;

    /**
     * Step 1: a media container. Instagram downloads `$imageUrl` itself, so
     * it must be a PUBLIC JPEG URL. `$story` creates a story (no caption).
     * Returns the container id.
     *
     * @throws MetaApiError
     */
    public function createImageContainer(string $igUserId, string $accessToken, string $imageUrl, null|string $caption, bool $story): string;

    /**
     * IN_PROGRESS | FINISHED | ERROR | EXPIRED | PUBLISHED
     *
     * @throws MetaApiError
     */
    public function containerStatus(string $containerId, string $accessToken): string;

    /**
     * Step 2: publish a FINISHED container. Returns the media id.
     *
     * @throws MetaApiError
     */
    public function publishContainer(string $igUserId, string $accessToken, string $containerId): string;

    /**
     * The public link of a published media, null when Instagram gives none.
     *
     * @throws MetaApiError
     */
    public function fetchPermalink(string $mediaId, string $accessToken): null|string;
}
