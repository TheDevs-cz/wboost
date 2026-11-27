<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\SocialPlatform;

/**
 * One post WBoost published to a project's social account ("Publikované
 * příspěvky"), recorded only on success. Keeps the network's ids
 * (`externalId` + everything else Meta returned in `response`) so later
 * features — comments, insights, deleting a post — can address it.
 *
 * Snapshots (template name, account name) survive the template being deleted
 * or the account reconnected elsewhere. `fillValuesHash` ties the post to the
 * export version with the same fill ("Historie exportů" shows a pill) without
 * an FK: versions deduplicate, prune and promote drafts.
 */
#[Entity]
#[Table(name: 'social_post')]
#[Index(name: 'idx_social_post_project_published', columns: ['project_id', 'published_at'])]
#[Index(name: 'idx_social_post_variant_hash', columns: ['template_variant_id', 'fill_values_hash'])]
class SocialPost
{
    /**
     * @param array<string, scalar|null> $response
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        readonly public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        readonly public Project $project,

        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        readonly public null|TemplateVariant $templateVariant,

        #[Column(type: UuidType::NAME, nullable: true)]
        readonly public null|UuidInterface $templateId,

        #[Column]
        readonly public string $templateName,

        #[Column(type: 'string', enumType: SocialPlatform::class)]
        readonly public SocialPlatform $platform,

        /** `feed` / `story` (Instagram) or `feed` (a Facebook Page photo post). */
        #[Column(length: 32)]
        readonly public string $kind,

        #[Column]
        readonly public string $accountId,

        /** `@handle` for Instagram, the Page name for Facebook. */
        #[Column]
        readonly public string $accountName,

        /** Instagram media id / Facebook post id. */
        #[Column]
        readonly public string $externalId,

        #[Column(type: Types::TEXT, nullable: true)]
        readonly public null|string $permalink,

        #[Column(type: Types::TEXT)]
        readonly public string $caption,

        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        readonly public null|User $publishedBy,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $publishedAt,

        #[Column(length: 64, nullable: true)]
        readonly public null|string $fillValuesHash,

        #[Column(type: Types::JSON)]
        readonly public array $response,
    ) {
    }

    public function isStory(): bool
    {
        return $this->kind === 'story';
    }
}
