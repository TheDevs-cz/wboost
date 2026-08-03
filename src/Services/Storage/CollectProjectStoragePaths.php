<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Storage;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\ProjectStoragePaths;

/**
 * Every storage key namespace that belongs to one project, so deleting the
 * project can take its files with it.
 *
 * **Must be called BEFORE the project row is removed** — most namespaces are
 * keyed by a child entity's id (manual, template, variant), not by the project
 * id, and the FK cascade wipes those rows. Once they are gone the files are
 * unreachable forever: nothing else records which project they belonged to, and
 * an image can never be referenced across projects (the gallery picker, the
 * font list and the placeholder folders are all project-scoped), so there is no
 * later chance to reattach them either.
 *
 * Only two prefixes are keyed by the project itself (`file-upload/`, `fonts/`);
 * the rest have to be collected from the children while they still exist.
 */
readonly final class CollectProjectStoragePaths
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function collect(UuidInterface $projectId): ProjectStoragePaths
    {
        $id = $projectId->toString();

        $directories = [
            sprintf('file-upload/%s', $id),
            sprintf('fonts/%s', $id),
        ];
        $files = [];

        foreach ($this->ids('SELECT id FROM manual WHERE project_id = ?', $id) as $manualId) {
            // Mockup page images live under `manuals/{manualId}/pages/...`, so
            // the manual prefix covers them too.
            $directories[] = sprintf('manuals/%s', $manualId);
        }

        foreach ($this->ids('SELECT id FROM social_network_template WHERE project_id = ?', $id) as $templateId) {
            $directories[] = sprintf('social-networks/templates/%s', $templateId);
        }

        foreach ($this->ids(
            'SELECT v.id FROM social_network_template_variant v
             JOIN social_network_template t ON t.id = v.template_id
             WHERE t.project_id = ?',
            $id,
        ) as $variantId) {
            $directories[] = sprintf('social-networks/%s', $variantId);
            // Previews are one deterministic FILE inside a shared folder — the
            // folder itself holds every project's previews and must never be
            // deleted as a directory.
            $files[] = sprintf('social-networks/preview/%s.png', $variantId);
        }

        foreach ($this->ids('SELECT id FROM custom_template WHERE project_id = ?', $id) as $templateId) {
            $directories[] = sprintf('custom-templates/templates/%s', $templateId);
        }

        foreach ($this->ids(
            'SELECT v.id FROM custom_template_variant v
             JOIN custom_template t ON t.id = v.template_id
             WHERE t.project_id = ?',
            $id,
        ) as $variantId) {
            $directories[] = sprintf('custom-templates/%s', $variantId);
            $files[] = sprintf('custom-templates/preview/%s.png', $variantId);
        }

        foreach ($this->ids('SELECT id FROM email_signature_template WHERE project_id = ?', $id) as $templateId) {
            $directories[] = sprintf('emails/%s', $templateId);
            // AddEmailSignatureTemplateHandler writes backgrounds under
            // `emails/{id}/` but the EDIT handler writes them under
            // `manuals/{id}/` — a long-standing prefix mismatch. Both are keyed
            // by the SAME template id, and ids are UUIDs, so clearing both can
            // never touch a real manual's folder.
            $directories[] = sprintf('manuals/%s', $templateId);
        }

        return new ProjectStoragePaths($directories, $files);
    }

    /**
     * @return list<string>
     */
    private function ids(string $sql, string $projectId): array
    {
        $ids = [];

        foreach ($this->entityManager->getConnection()->iterateColumn($sql, [$projectId]) as $value) {
            if (is_string($value)) {
                $ids[] = $value;
            }
        }

        return $ids;
    }
}
