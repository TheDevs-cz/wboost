<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;
use WBoost\Web\Entity\Manual;

/**
 * F7 P5a → P5b bridge, TEMPORARY: `manual.detected_colors` /
 * `manual.custom_colors` (the retired JSONB colour mirror) are no longer
 * mapped — the ORM never reads or writes them — but they stay in the database
 * until the P5b DROP migration ships separately. This puts them back into the
 * schema the ORM GENERATES (schema tool, `doctrine:schema:validate`,
 * `migrations:diff`), so the mapping still matches the database and CI's
 * "migrations up to date" check stays green in between.
 *
 * NOT_NULL with DEFAULT '[]' exactly as the database has them, so inserts that
 * leave them out keep working. Delete this class together with the columns (P5b).
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchemaTable)]
final class KeepRetiredManualColorColumns
{
    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        if ($args->getClassMetadata()->getName() !== Manual::class) {
            return;
        }

        $table = $args->getClassTable();

        foreach (['detected_colors', 'custom_colors'] as $column) {
            $table->addColumn($column, Types::JSON, [
                'notnull' => true,
                'default' => '[]',
                'platformOptions' => ['jsonb' => true],
            ]);
        }
    }
}
