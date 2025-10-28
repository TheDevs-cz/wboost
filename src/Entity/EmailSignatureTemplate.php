<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Doctrine\EmailTextInputsDoctrineType;
use WBoost\Web\Value\EmailTextInput;

#[Entity]
class EmailSignatureTemplate
{
    /** @var Collection<int, EmailSignatureVariant>  */
    #[Immutable]
    #[OneToMany(targetEntity: EmailSignatureVariant::class, mappedBy: 'template', fetch: 'EAGER')]
    private Collection $variants;

    /** @var array<EmailTextInput> */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: EmailTextInputsDoctrineType::NAME)]
    public array $textInputs = [];

    /** @var array<string, string> */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON)]
    public array $vcardInfo = [];

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        readonly public Project $project,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public \DateTimeImmutable $createdAt,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::TEXT)]
        public string $code,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $backgroundImage,
    ) {
        $this->variants = new ArrayCollection();
    }

    public function edit(string $name, null|string $backgroundImage): void
    {
        $this->name = $name;
        $this->backgroundImage = $backgroundImage;
    }

    /**
     * @param array<EmailTextInput> $textInputs
     */
    public function changeCode(string $code, array $textInputs): void
    {
        $this->code = $code;
        $this->textInputs = $textInputs;
    }

    /**
     * @return array<EmailSignatureVariant>
     */
    public function variants(): array
    {
        return $this->variants->toArray();
    }
}
