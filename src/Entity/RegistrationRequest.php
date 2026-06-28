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
use WBoost\Web\Value\RegistrationRequestStatus;

#[Entity]
#[Table(name: 'registration_request')]
class RegistrationRequest
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: 'string', enumType: RegistrationRequestStatus::class)]
    public RegistrationRequestStatus $status;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Column]
        readonly public string $email,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $createdAt,
    ) {
        $this->status = RegistrationRequestStatus::Pending;
    }

    public function markInvited(): void
    {
        $this->status = RegistrationRequestStatus::Invited;
    }

    public function markDismissed(): void
    {
        $this->status = RegistrationRequestStatus::Dismissed;
    }
}
