<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Meta;

use League\Flysystem\Filesystem;
use WBoost\Web\Exceptions\InstagramContainerFailed;
use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Services\PngToJpeg;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\FacebookPage;

/**
 * Instagram publishing (professional account linked to a Facebook Page).
 * Meta fetches the image itself from a public URL and accepts JPEG only, so
 * the flow is: PNG → JPEG → temp object on the public Minio bucket → create
 * media container (Meta downloads it) → poll processing → publish → delete
 * the temp object. Requires an internet-reachable UPLOADS_BASE_URL.
 */
readonly final class PublishToInstagram
{
    private const int STATUS_POLL_ATTEMPTS = 10;
    private const int STATUS_POLL_DELAY_MICROSECONDS = 1_500_000;

    public function __construct(
        private MetaGraphApiInterface $metaGraphApi,
        private PngToJpeg $pngToJpeg,
        private Filesystem $filesystem,
        private UploaderHelper $uploaderHelper,
        private ProvideIdentity $provideIdentity,
    ) {
    }

    /**
     * Returns the published IG media id.
     *
     * @throws MetaApiError
     */
    public function publish(FacebookPage $page, string $pngBytes, string $caption): string
    {
        $igUserId = $page->instagramUserId;

        if ($igUserId === null) {
            throw new InstagramContainerFailed('Page has no linked Instagram professional account.');
        }

        $jpegBytes = $this->pngToJpeg->convert($pngBytes);
        $tempPath = sprintf('social-publish/%s.jpg', $this->provideIdentity->next()->toString());

        $this->filesystem->write($tempPath, $jpegBytes);

        try {
            $containerId = $this->metaGraphApi->createInstagramContainer(
                $igUserId,
                $page->accessToken,
                $this->uploaderHelper->getPublicPath($tempPath),
                $caption,
            );

            $this->waitForContainer($containerId, $page->accessToken);

            return $this->metaGraphApi->publishInstagramContainer($igUserId, $page->accessToken, $containerId);
        } finally {
            try {
                $this->filesystem->delete($tempPath);
            } catch (\Throwable) {
                // Leftover temp objects are harmless; never mask the real outcome.
            }
        }
    }

    /**
     * Image containers usually finish within a second or two; poll briefly
     * and fail loudly instead of publishing an unfinished container.
     *
     * @throws MetaApiError
     */
    private function waitForContainer(string $containerId, string $accessToken): void
    {
        for ($attempt = 0; $attempt < self::STATUS_POLL_ATTEMPTS; $attempt++) {
            $status = $this->metaGraphApi->getInstagramContainerStatus($containerId, $accessToken);

            if ($status === 'FINISHED') {
                return;
            }

            if ($status === 'ERROR' || $status === 'EXPIRED') {
                throw new InstagramContainerFailed(sprintf('Instagram container ended in status %s.', $status));
            }

            usleep(self::STATUS_POLL_DELAY_MICROSECONDS);
        }

        throw new InstagramContainerFailed('Instagram container did not finish processing in time.');
    }
}
