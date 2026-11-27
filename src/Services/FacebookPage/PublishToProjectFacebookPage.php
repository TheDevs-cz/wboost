<?php

declare(strict_types=1);

namespace WBoost\Web\Services\FacebookPage;

use Psr\Log\LoggerInterface;
use WBoost\Web\Entity\ProjectSocialAccount;
use WBoost\Web\Exceptions\FacebookPageTokenExpired;
use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Value\FacebookPagePublication;

/**
 * Photo post on the project's Facebook Page: the rendered PNG is uploaded
 * directly (multipart — any size, no padding, no public URL), then the
 * permalink is read (cosmetic: a failure falls back to facebook.com/{post}).
 * A 190 flags the connection for reconnect.
 */
readonly final class PublishToProjectFacebookPage
{
    public function __construct(
        private FacebookPageApiInterface $api,
        private ProjectFacebookPageConnection $connection,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws MetaApiError
     */
    public function publish(ProjectSocialAccount $account, string $pngBytes, string $caption): FacebookPagePublication
    {
        $pageToken = $this->connection->accessToken($account);

        try {
            $postId = $this->api->publishPhoto($account->accountId, $pageToken, $pngBytes, $caption);
        } catch (FacebookPageTokenExpired $exception) {
            $this->connection->markNeedsReconnect($account);

            throw $exception;
        }

        try {
            $permalink = $this->api->fetchPermalink($postId, $pageToken);
        } catch (MetaApiError $exception) {
            $this->logger->warning('Facebook permalink lookup failed.', ['exception' => $exception]);
            $permalink = null;
        }

        return new FacebookPagePublication($postId, $permalink ?? 'https://www.facebook.com/' . $postId);
    }
}
