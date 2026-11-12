<?php

declare(strict_types=1);

namespace WBoost\Web\Twig;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Services\AiScan\AiScanLists;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Value\FileSource;

/**
 * What the template list reads about AI scan (docs/architecture/ai-scan.md
 * § "UI"): the entry card + strip and the two pills on a template's card.
 */
final readonly class AiScanExtension
{
    public function __construct(
        private Security $security,
        private AiScanLists $lists,
        private Connection $connection,
    ) {
    }

    /**
     * The entry document ({@see AiScanLists::entry()}) for the signed-in
     * user — the state of the card "Šablona z obrázku" and the rows of the
     * strip. Null for a visitor and for somebody who cannot edit the project
     * (they see no card).
     *
     * @return null|array<string, mixed>
     */
    #[AsTwigFunction('ai_scan_entry')]
    public function entry(Project $project): null|array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User || !$this->security->isGranted(ProjectVoter::EDIT, $project)) {
            return null;
        }

        return $this->lists->entry($project, $user);
    }

    /**
     * Marks on the template cards: `fromScan` — template id → scan id for
     * templates made from a scan in the last week (pill `Ze skenu`);
     * `unfilled` — template id → pictures still on the stand-in (pill
     * `K doplnění: {n}`).
     *
     * A project that never accepted a scan costs two small indexed reads and
     * no canvas: the stand-in row exists only once an accept needed it, and
     * only then are canvases looked at — in the database, by the stand-in's
     * path.
     *
     * @return array{fromScan: array<string, string>, unfilled: array<string, int>}
     */
    #[AsTwigFunction('ai_scan_template_marks')]
    public function templateMarks(Project $project): array
    {
        $projectId = $project->id->toString();
        $accepted = $this->connection->fetchOne("SELECT 1 FROM ai_scan WHERE project_id = :project AND status = 'accepted' LIMIT 1", ['project' => $projectId]);

        if ($accepted === false) {
            return ['fromScan' => [], 'unfilled' => []];
        }

        return ['fromScan' => $this->lists->recentlyCreatedTemplates($project), 'unfilled' => $this->unfilled($projectId)];
    }

    /** `z` / `ze` by the number that follows (docs/ai-scan/ux.md §2.4). */
    #[AsTwigFunction('ai_scan_zz')]
    public function zz(int $number): string
    {
        return in_array($number, [2, 3, 4, 6, 7, 12, 13, 14, 16, 17, 100, 200], true) ? 'ze' : 'z';
    }

    /** `2026-12-01` → `1. prosince`. */
    #[AsTwigFunction('ai_scan_day_month')]
    public function dayMonth(string $date): string
    {
        $months = ['ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));

        return $parsed === false ? '' : sprintf('%d. %s', (int) $parsed->format('j'), $months[(int) $parsed->format('n') - 1]);
    }

    /**
     * @return array<string, int>
     */
    private function unfilled(string $projectId): array
    {
        /** @var list<string> $paths */
        $paths = $this->connection->fetchFirstColumn(
            'SELECT path FROM file_upload WHERE project_id = :project AND source = :source',
            ['project' => $projectId, 'source' => FileSource::ScanStandIn->value],
        );

        if ($paths === []) {
            return [];
        }

        // Per template the most its variants owe (one design in several
        // formats is one thing to fill, not several).
        /** @var array<string, int|string> $rows */
        $rows = $this->connection->fetchAllKeyValue(
            <<<'SQL'
                SELECT template_id, MAX(n) FROM (
                    SELECT v.template_id, (
                        SELECT COUNT(*) FROM jsonb_array_elements(CASE WHEN jsonb_typeof(v.canvas -> 'objects') = 'array' THEN v.canvas -> 'objects' ELSE '[]'::jsonb END) AS o
                         WHERE o ->> 'assetPath' IN (:paths)
                    ) AS n
                      FROM template_variant v
                      JOIN template t ON t.id = v.template_id
                     WHERE t.project_id = :project
                ) AS counted
                 WHERE n > 0
                 GROUP BY template_id
                SQL,
            ['project' => $projectId, 'paths' => $paths],
            ['paths' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return array_map(static fn (int|string $count): int => (int) $count, $rows);
    }
}
