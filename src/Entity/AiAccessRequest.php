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
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\AiScan\AiAccessRequestStatus;

/**
 * "Požádat o přístup" (ux.md §9.1): a project editor of a project without
 * AI scan asks for it. Stored, e-mailed to the admins' notification list,
 * resolved by an admin (`approved` switches the project on).
 *
 * **One open request per user and project** — a partial unique index
 * (`uniq_ai_access_request_open`) makes it true under a race. The row stays
 * after it is resolved and after the user is gone (the e-mail and name are
 * copied onto it).
 */
#[Entity]
#[Table(name: 'ai_access_request')]
#[Index(name: 'idx_ai_access_request_status', columns: ['status', 'created_at'])]
#[Index(name: 'idx_ai_access_request_user', columns: ['user_id', 'created_at'])]
#[UniqueConstraint(name: 'uniq_ai_access_request_open', columns: ['user_id', 'project_id'], options: ['where' => "((status)::text = 'open'::text)"])]
class AiAccessRequest
{
    public const int MESSAGE_MAX = 500;

    #[Column(type: 'string', length: 16, enumType: AiAccessRequestStatus::class)]
    public AiAccessRequestStatus $status = AiAccessRequestStatus::Open;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $resolvedAt = null;

    #[ManyToOne]
    #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public null|User $resolvedBy = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Project $project,

        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|User $user,

        #[Immutable]
        #[Column(length: 180)]
        public string $requesterEmail,

        #[Immutable]
        #[Column(length: 255)]
        public string $requesterName,

        /** Where the request was sent from: `templates` or `calendars`. */
        #[Immutable]
        #[Column(length: 16)]
        public string $origin,

        #[Immutable]
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $message,

        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
    }
}
