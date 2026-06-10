<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embedded;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Doctrine\CanvasJsonbType;
use WBoost\Web\Doctrine\EditorImageInputsDoctrineType;
use WBoost\Web\Doctrine\EditorTextInputsDoctrineType;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\FlyerDimension;

#[Entity]
class FlyerTemplateVariant
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: CanvasJsonbType::NAME)]
    public string $canvas = '{}';

    /**
     * Path to the preview PNG inside the upload (Minio) filesystem.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, length: 255, nullable: true)]
    public null|string $previewImagePath = null;

    /** @var array<EditorTextInput> */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: EditorTextInputsDoctrineType::NAME)]
    public array $inputs = [];

    /** @var array<EditorImageInput> */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: EditorImageInputsDoctrineType::NAME)]
    public array $imageInputs = [];

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne(inversedBy: 'variants')]
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        public FlyerTemplate $template,

        #[Embedded(class: FlyerDimension::class, columnPrefix: 'dimension_')]
        readonly public FlyerDimension $dimension,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $backgroundImage,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<EditorTextInput> $inputs
     * @param array<EditorImageInput> $imageInputs
     */
    public function editCanvas(
        string $canvas,
        array $inputs,
        null|string $previewImagePath,
        array $imageInputs = [],
    ): void
    {
        $this->canvas = $canvas;
        $this->inputs = $inputs;
        $this->imageInputs = $imageInputs;
        $this->previewImagePath = $previewImagePath;
    }

    public function edit(string $backgroundImagePath): void
    {
        $this->backgroundImage = $backgroundImagePath;
    }
}
