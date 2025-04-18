<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class EmailSignatureVariant
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne(inversedBy: 'variants')]
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        public EmailSignatureTemplate $template,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public \DateTimeImmutable $createdAt,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::TEXT)]
        public string $name,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::TEXT)]
        public string $code = '',

        /** @var array<string, string> */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSON)]
        public array $textInputs = [],
    ) {
    }

    /**
     * @param array<string, string> $textInputs
     */
    public function edit(string $name, string $code, array $textInputs): void
    {
        $this->name = $name;
        $this->code = $code;
        $this->textInputs = $textInputs;
    }

    public function inputValue(string $inputId): null|string
    {
        foreach ($this->textInputs as $id => $value) {
            if ($inputId === $id) {
                return $value;
            }
        }

        return null;
    }
}
