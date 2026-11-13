<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * "The admins were told that this project's starts are paused today" — one
 * row per project and day (Europe/Prague), inserted by the watch
 * ({@see \WBoost\Web\Services\AiScan\AiScanAlerts::claimProjectPause()}).
 *
 * It is the CLAIM of that one mail and nothing else: the pause itself is
 * never stored — it is counted from `ai_usage_event` at every start
 * (`AiScans::assertMayStart()`, three refusals by the provider in the project
 * today) and ends by itself at midnight. FK-less on purpose, like the usage
 * rows it is counted from. Raw SQL only; this class is the schema.
 */
#[Entity]
#[Table(name: 'ai_scan_project_pause')]
class AiScanProjectPause
{
    /** How many refusals the day had when the mail went out. */
    #[Column(options: ['default' => 0])]
    public int $refusals = 0;

    public function __construct(
        #[Id]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $projectId,

        /** The day in Europe/Prague, `YYYY-MM-DD`. */
        #[Id]
        #[Column(length: 10)]
        public string $day,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $announcedAt,
    ) {
    }
}
