<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity]
class WeeklyMenuApprovalAuditLog
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne(targetEntity: WeeklyMenu::class, inversedBy: 'auditLogs')]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        readonly public WeeklyMenu $weeklyMenu,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $createdAt,

        #[Column]
        readonly public string $event,

        #[Column(nullable: true)]
        readonly public null|string $performedBy = null,

        #[Column(type: Types::TEXT, nullable: true)]
        readonly public null|string $comment = null,
    ) {
    }
}
