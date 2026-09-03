<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Doctrine\MockupPageDownloadDoctrineType;
use WBoost\Web\Doctrine\MockupPageDownloadsDoctrineType;
use WBoost\Web\Value\MockupPageDownload;
use WBoost\Web\Value\MockupPageLayout;

#[Entity]
class ManualMockupPage
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne(fetch: 'EXTRA_LAZY', inversedBy: 'pages')]
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        readonly public Manual $manual,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $createdAt,

        #[Column]
        readonly public MockupPageLayout $layout,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,

        /**
         * @var array<int, string|null>
         */
        #[Column(type: Types::JSON)]
        public array $images,

        #[Column(options: ['default' => 0])]
        public int $position,

        /**
         * A file offered for download next to the whole page in the manual.
         */
        #[Column(type: MockupPageDownloadDoctrineType::NAME, nullable: true)]
        public null|MockupPageDownload $downloadFile = null,

        /**
         * Per-slot download files, positionally aligned with $images — index i
         * belongs to the same slot as image i, and a slot without a file keeps
         * a null hole so the alignment survives.
         *
         * @var array<int, null|MockupPageDownload>
         */
        #[Column(type: MockupPageDownloadsDoctrineType::NAME, options: ['default' => '[]'])]
        public array $imageDownloads = [],
    ) {
    }

    /**
     * @param array<int, string|null> $images
     * @param array<int, null|MockupPageDownload> $imageDownloads
     */
    public function edit(
        string $name,
        array $images,
        null|MockupPageDownload $downloadFile,
        array $imageDownloads,
    ): void {
        $this->name = $name;
        $this->images = $images;
        $this->downloadFile = $downloadFile;
        $this->imageDownloads = $imageDownloads;
    }

    public function sort(int $position): void
    {
        $this->position = $position;
    }

    public function imageDownload(int $slotIndex): null|MockupPageDownload
    {
        return $this->imageDownloads[$slotIndex] ?? null;
    }

    public function hasAnyDownload(): bool
    {
        if ($this->downloadFile !== null) {
            return true;
        }

        foreach ($this->imageDownloads as $download) {
            if ($download !== null) {
                return true;
            }
        }

        return false;
    }
}
