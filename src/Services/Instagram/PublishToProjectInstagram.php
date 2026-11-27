<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Instagram;

use League\Flysystem\Filesystem;
use WBoost\Web\Entity\ProjectSocialAccount;
use WBoost\Web\Exceptions\InstagramContainerFailed;
use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\InstagramPostKind;
use WBoost\Web\Value\InstagramPublication;

/**
 * Publishes one rendered picture to a project's Instagram account. Instagram
 * downloads the picture itself from a public URL and accepts JPEG only, so:
 * PNG → JPEG → temp object on the public bucket (`social-publish/…`) →
 * media container (Instagram fetches it) → wait until FINISHED → publish →
 * permalink → delete the temp object. Needs an internet-reachable
 * UPLOADS_BASE_URL (on localhost Instagram cannot fetch the picture).
 */
readonly final class PublishToProjectInstagram
{
    private const int STATUS_POLL_ATTEMPTS = 12;
    private const int STATUS_POLL_DELAY_MICROSECONDS = 1_500_000;

    public function __construct(
        private InstagramApiInterface $instagramApi,
        private ProjectInstagramConnection $connection,
        private InstagramImageFit $imageFit,
        private Filesystem $filesystem,
        private UploaderHelper $uploaderHelper,
        private ProvideIdentity $provideIdentity,
        private int $statusPollDelayMicroseconds = self::STATUS_POLL_DELAY_MICROSECONDS,
    ) {
    }

    /**
     * @throws MetaApiError
     */
    public function publish(ProjectSocialAccount $account, string $pngBytes, string $caption, InstagramPostKind $kind): InstagramPublication
    {
        $accessToken = $this->connection->accessToken($account);
        $tempPath = sprintf('social-publish/%s.jpg', $this->provideIdentity->next()->toString());

        $this->filesystem->write($tempPath, $this->imageFit->toJpeg($pngBytes, $kind));

        try {
            $containerId = $this->instagramApi->createImageContainer(
                $account->accountId,
                $accessToken,
                $this->uploaderHelper->getPublicPath($tempPath),
                $kind->supportsCaption() ? $caption : null,
                $kind === InstagramPostKind::Story,
            );

            $this->waitForContainer($containerId, $accessToken);

            $mediaId = $this->instagramApi->publishContainer($account->accountId, $accessToken, $containerId);
        } catch (MetaApiError $error) {
            $this->connection->handleApiError($account, $error);
        } finally {
            try {
                $this->filesystem->delete($tempPath);
            } catch (\Throwable) {
                // Leftover temp objects are harmless; never mask the real outcome.
            }
        }

        try {
            $permalink = $this->instagramApi->fetchPermalink($mediaId, $accessToken);
        } catch (MetaApiError) {
            // The post is live; a missing link is cosmetic.
            $permalink = null;
        }

        return new InstagramPublication($mediaId, $permalink, $kind, [
            'igUserId' => $account->accountId,
            'containerId' => $containerId,
            'mediaId' => $mediaId,
            'permalink' => $permalink,
        ]);
    }

    /**
     * Image containers usually finish within seconds; poll briefly and fail
     * loudly instead of publishing an unfinished container.
     *
     * @throws MetaApiError
     */
    private function waitForContainer(string $containerId, string $accessToken): void
    {
        for ($attempt = 0; $attempt < self::STATUS_POLL_ATTEMPTS; $attempt++) {
            $status = $this->instagramApi->containerStatus($containerId, $accessToken);

            if ($status === 'FINISHED') {
                return;
            }

            if ($status === 'ERROR' || $status === 'EXPIRED') {
                throw new InstagramContainerFailed(sprintf('Instagram container ended in status %s.', $status));
            }

            usleep($this->statusPollDelayMicroseconds);
        }

        throw new InstagramContainerFailed('Instagram container did not finish processing in time.');
    }
}
