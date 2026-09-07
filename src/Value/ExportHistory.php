<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use WBoost\Web\Entity\TemplateExportVersion;

/**
 * One fill surface's export history, split the way every surface presents
 * it: the PINNED versions (most recently pinned first) ahead of the rest
 * (freshest export first). The fill page's dropdown shows all pinned + the
 * {@see self::MENU_RECENT} most recent unpinned ones and links to the
 * dedicated history page for everything.
 */
final readonly class ExportHistory
{
    public const int MENU_RECENT = 5;

    /**
     * @param list<TemplateExportVersion> $pinned most recently pinned first
     * @param list<TemplateExportVersion> $unpinned freshest export first
     */
    private function __construct(
        public array $pinned,
        public array $unpinned,
    ) {
    }

    /**
     * @param list<TemplateExportVersion> $versions in any order
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
        $freshestFirst = static fn (TemplateExportVersion $a, TemplateExportVersion $b): int => ($b->lastExportedAt <=> $a->lastExportedAt)
            ?: strcmp($b->id->toString(), $a->id->toString());

        usort(
            $pinned,
            static fn (TemplateExportVersion $a, TemplateExportVersion $b): int => ($b->pinnedAt <=> $a->pinnedAt) ?: $freshestFirst($a, $b),
        );
        usort($unpinned, $freshestFirst);

        return new self($pinned, $unpinned);
    }

    /**
     * Pinned first, then the rest — the order of the history page.
     *
     * @return list<TemplateExportVersion>
     */
    public function all(): array
    {
        return [...$this->pinned, ...$this->unpinned];
    }

    /**
     * The unpinned versions the dropdown shows (pinned ones have their own
     * section there, so they are never repeated here).
     *
     * @return list<TemplateExportVersion>
     */
    public function recent(int $limit = self::MENU_RECENT): array
    {
        return array_slice($this->unpinned, 0, $limit);
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
