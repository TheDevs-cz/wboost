<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Meta;

use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Value\FacebookPage;

/**
 * Facebook Page photo post: the PNG is uploaded DIRECTLY as multipart (no
 * public URL needed, unlike Instagram). Returns the created post id.
 */
readonly final class PublishToFacebookPage
{
    public function __construct(
        private MetaGraphApiInterface $metaGraphApi,
    ) {
    }

    /**
     * @throws MetaApiError
     */
    public function publish(FacebookPage $page, string $pngBytes, string $caption): string
    {
        return $this->metaGraphApi->publishPagePhoto($page->id, $page->accessToken, $pngBytes, $caption);
    }
}
