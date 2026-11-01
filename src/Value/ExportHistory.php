<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use WBoost\Web\Entity\CuratableExportVersion;

/**
 * One fill surface's export history, split the way every surface presents
 * it: the PINNED versions (most recently pinned first) ahead of the rest
 * (freshest export first). The fill page's dropdown shows all pinned + the
 * {@see self::MENU_RECENT} most recent unpinned ones and links to the
 * dedicated history page for everything.
 *
 * Subject-agnostic: a variant / group history holds
 * {@see \WBoost\Web\Entity\TemplateExportVersion}s, a document history
 * {@see \WBoost\Web\Entity\TemplateDocumentExportVersion}s.
 *
 * @template T of CuratableExportVersion
 */
final readonly class ExportHistory
{
    public const int MENU_RECENT = 5;

    /**
     * @param list<T> $pinned most recently pinned first
     * @param list<T> $unpinned freshest export first
     */
    private function __construct(
        public array $pinned,
        public array $unpinned,
    ) {
    }

    /**
     * @template V of CuratableExportVersion
     * @param list<V> $versions in any order
     * @return self<V>
     */
    public static function fromVersions(array $versions): self
    {
        $pinned = [];
        $unpinned = [];

        foreach ($versions as $version) {
            if ($version->isPinned()) {
                $pinned[] = $version;
            } else {
                $unpinned[] = $version;
            }
        }

        // Ties (a second's worth of exports, or two pins in one request)
        // break on the UUID v7 id — newest-minted first — so the order is
        // deterministic across reloads.
        $freshestFirst = static fn (CuratableExportVersion $a, CuratableExportVersion $b): int => ($b->lastExportedAt <=> $a->lastExportedAt)
            ?: strcmp($b->id->toString(), $a->id->toString());

        usort(
            $pinned,
            static fn (CuratableExportVersion $a, CuratableExportVersion $b): int => ($b->pinnedAt <=> $a->pinnedAt) ?: $freshestFirst($a, $b),
        );
        usort($unpinned, $freshestFirst);

        return new self($pinned, $unpinned);
    }

    /**
     * Pinned first, then the rest — the order of the history page.
     *
     * @return list<T>
     */
    public function all(): array
    {
        return [...$this->pinned, ...$this->unpinned];
    }

    /**
     * The unpinned versions the dropdown shows (pinned ones have their own
     * section there, so they are never repeated here).
     *
     * @return list<T>
     */
    public function recent(int $limit = self::MENU_RECENT): array
    {
        return array_slice($this->unpinned, 0, $limit);
    }

    /**
     * Every version a fill page renders curation controls for: the dropdown's
     * pinned + recent rows, plus the loaded one — its banner carries a rename
     * and a pin too, and an old unpinned version is not among the recent
     * rows. The out-of-line form anchors
     * (`_export_version_form_anchors.html.twig`) are rendered from this list.
     *
     * @param null|T $loaded
     * @return list<T>
     */
    public function menu(?CuratableExportVersion $loaded = null): array
    {
        $versions = [...$this->pinned, ...$this->recent()];
        if ($loaded !== null) {
            $versions[] = $loaded;
        }

        $unique = [];
        foreach ($versions as $version) {
            $unique[$version->id->toString()] ??= $version;
        }

        return array_values($unique);
    }

    /**
     * Whether the dropdown's recent section leaves something out — the
     * "Zobrazit vše" link's reason to exist beyond curation.
     */
    public function hasMoreThanRecent(int $limit = self::MENU_RECENT): bool
    {
        return count($this->unpinned) > $limit;
    }

    public function total(): int
    {
        return count($this->pinned) + count($this->unpinned);
    }

    public function isEmpty(): bool
    {
        return $this->pinned === [] && $this->unpinned === [];
    }
}
