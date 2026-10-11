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
use WBoost\Web\Value\ProjectModule;

/**
 * One switch of a project module on or off — the history the admin sees in
 * the project's "Moduly projektu" card ("Lukáš zapnul modul E-maily").
 */
#[Entity]
#[Table(name: 'project_module_change')]
#[Index(columns: ['project_id', 'changed_at'])]
class ProjectModuleChange
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        readonly public Project $project,

        #[Column(type: 'string', enumType: ProjectModule::class)]
        readonly public ProjectModule $module,

        #[Column]
        readonly public bool $enabled,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $changedAt,

        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        readonly public null|User $changedBy = null,
    ) {
    }
}
