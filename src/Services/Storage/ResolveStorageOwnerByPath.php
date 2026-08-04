<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Storage;

use Doctrine\ORM\EntityManagerInterface;
use WBoost\Web\Value\StorageOwner;

/**
 * Attributes an UNREFERENCED object to a project by reading the entity id out
 * of its storage key.
 *
 * Every writer namespaces its keys by the id of the thing the file belongs to
 * (`manuals/{manualId}/…`, `social-networks/{variantId}/…`,
 * `file-upload/{projectId}/…`, `custom-templates/preview/{variantId}.png`, …),
 * so an orphan left behind by a re-upload — by far the most common kind, since
 * every `Edit*` handler writes a new timestamped key and abandons the old one —
 * can still be billed to the right project even though nothing points at it any
 * more.
 *
 * Ids are globally unique UUIDs, so one flat map over every namespacing entity
 * is enough; taking the FIRST uuid in the path is what makes nested keys
 * (`manuals/{manualId}/pages/{pageId}/…`) resolve to the outer owner. Objects
 * whose entity is genuinely gone (a deleted project) stay unattributed —
 * that is the honest answer, not a guess.
 */
readonly final class ResolveStorageOwnerByPath
{
    private const string UUID_PATTERN = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, StorageOwner> keyed by lower-case entity UUID
     */
    public function buildIndex(): array
    {
        $ownerColumns = 'p.id AS project_id, p.name AS project_name, u.id AS owner_id, u.email AS owner_email';
        $ownerJoin = 'JOIN project p ON p.id = %s JOIN "user" u ON u.id = p.owner_id';

        $sql = implode("\nUNION ALL\n", [
            sprintf('SELECT p.id AS entity_id, %s FROM project p JOIN "user" u ON u.id = p.owner_id', $ownerColumns),
            sprintf('SELECT m.id AS entity_id, %s FROM manual m %s', $ownerColumns, sprintf($ownerJoin, 'm.project_id')),
            // Former-social keys (`social-networks/{id}/…`) resolve through the
            // unified template tables — the merge kept the row UUIDs.
            sprintf('SELECT t.id AS entity_id, %s FROM template t %s', $ownerColumns, sprintf($ownerJoin, 't.project_id')),
            sprintf(
                'SELECT v.id AS entity_id, %s FROM template_variant v JOIN template t ON t.id = v.template_id %s',
                $ownerColumns,
                sprintf($ownerJoin, 't.project_id'),
            ),
            sprintf('SELECT t.id AS entity_id, %s FROM email_signature_template t %s', $ownerColumns, sprintf($ownerJoin, 't.project_id')),
            sprintf('SELECT f.id AS entity_id, %s FROM font f %s', $ownerColumns, sprintf($ownerJoin, 'f.project_id')),
        ]);

        $index = [];

        foreach ($this->entityManager->getConnection()->iterateAssociative($sql) as $row) {
            $entityId = $row['entity_id'];

            if (!is_string($entityId)) {
                continue;
            }

            $index[strtolower($entityId)] = new StorageOwner(
                is_string($row['project_id']) ? $row['project_id'] : null,
                is_string($row['project_name']) ? $row['project_name'] : null,
                is_string($row['owner_id']) ? $row['owner_id'] : null,
                is_string($row['owner_email']) ? $row['owner_email'] : null,
            );
        }

        return $index;
    }

    /**
     * @param array<string, StorageOwner> $index from {@see buildIndex()}
     */
    public function resolve(array $index, string $path): StorageOwner
    {
        if (preg_match(self::UUID_PATTERN, $path, $matches) !== 1) {
            return new StorageOwner();
        }

        return $index[strtolower($matches[0])] ?? new StorageOwner();
    }
}
