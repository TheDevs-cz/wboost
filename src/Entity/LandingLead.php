<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\LandingLeadSource;

/**
 * A contact left on the static landing site (`/`, `/klienti`) — see
 * landing/README.md. Stored even though it is also e-mailed, so a lead is never
 * lost to a mail failure; listed at /admin/landing-leads.
 */
#[Entity]
#[Table(name: 'landing_lead')]
class LandingLead
{
    /**
     * @param list<string> $interests
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Column(type: 'string', enumType: LandingLeadSource::class)]
        readonly public LandingLeadSource $source,

        #[Column]
        readonly public string $email,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $createdAt,

        #[Column(nullable: true)]
        readonly public null|string $name = null,

        #[Column(nullable: true)]
        readonly public null|string $phone = null,

        #[Column(nullable: true)]
        readonly public null|string $company = null,

        #[Column(nullable: true)]
        readonly public null|string $website = null,

        #[Column(type: Types::JSON, options: ['default' => '[]'])]
        readonly public array $interests = [],

        #[Column(nullable: true)]
        readonly public null|bool $hasDesigner = null,

        #[Column(nullable: true)]
        readonly public null|string $designerName = null,

        #[Column(nullable: true)]
        readonly public null|string $designerEmail = null,

        #[Column(type: Types::TEXT, nullable: true)]
        readonly public null|string $message = null,
    ) {
    }
}
