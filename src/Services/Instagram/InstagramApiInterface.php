<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Instagram;

use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Value\InstagramProfile;
use WBoost\Web\Value\InstagramToken;

/**
 * Thin wrapper over "Instagram API with Instagram Login" (api.instagram.com +
 * graph.instagram.com). Faked in tests via config/services_test.php — keep
 * every outbound Instagram call behind it.
 */
interface InstagramApiInterface
{
    /**
     * The consent URL people are sent to (Business Login for Instagram).
     *
     * @param list<string> $scopes
     */
    public function authorizationUrl(string $redirectUri, string $state, array $scopes): string;

    /**
     * Authorization code → short-lived token (1 hour) + the granted permissions.
     *
     * @throws MetaApiError
     */
    public function exchangeCode(string $code, string $redirectUri): InstagramToken;

    /**
     * Short-lived → long-lived (~60 days) token.
     *
     * @throws MetaApiError
     */
    public function exchangeLongLivedToken(string $shortLivedToken): InstagramToken;

    /**
     * Long-lived token at least 24 h old → a new one valid 60 days from now.
     *
     * @throws MetaApiError
     */
    public function refreshToken(string $longLivedToken): InstagramToken;

    /**
     * @throws MetaApiError
     */
    public function fetchProfile(string $accessToken): InstagramProfile;

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
