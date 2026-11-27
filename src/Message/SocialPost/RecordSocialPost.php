<?php

declare(strict_types=1);

namespace WBoost\Web\Message\SocialPost;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\SocialPlatform;

/**
 * A post was published to a project's social account — remember it
 * ("Publikované příspěvky", docs/architecture/social-publishing.md).
 */
readonly final class RecordSocialPost
{
    /**
     * @param array<string, scalar|null> $response
     */
    public function __construct(
        public UuidInterface $variantId,
        public null|UuidInterface $publishedByUserId,
        public SocialPlatform $platform,
        public string $kind,
        public string $accountId,
        public string $accountName,
        public string $externalId,
        public null|string $permalink,
        public string $caption,
        public null|string $fillValuesHash,
        public array $response,
    ) {
    }
}
