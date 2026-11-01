<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\ExportChannel;

/**
 * One row of an export history, whatever its subject — a variant / group
 * fill ({@see TemplateExportVersion}) or a multi-page document fill
 * ({@see TemplateDocumentExportVersion}). What the shared history chrome
 * (dropdown, history page, {@see \WBoost\Web\Value\ExportHistory}) reads;
 * the fill itself stays subject-specific.
 */
interface CuratableExportVersion
{
    public UuidInterface $id { get; }

    public null|string $name { get; }

    public null|DateTimeImmutable $pinnedAt { get; }

    public DateTimeImmutable $lastExportedAt { get; }

    public int $exportCount { get; }

    public bool $draft { get; }

    public ExportChannel $channel { get; }

    public null|User $exportedBy { get; }

    public function isPinned(): bool;
}
