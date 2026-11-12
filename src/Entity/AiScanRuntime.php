<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

/**
 * The ONE row (`id = 1`) that says how AI scan runs right now: operating
 * mode, limits, what is known about the provider and the scanner. In the
 * database because it must change without a deploy and survive a cache
 * restart. Read and written by
 * {@see \WBoost\Web\Services\AiScan\AiScanRuntimeState} (raw SQL); this class
 * is the schema.
 *
 * The defaults are the safe ones: `off`, admins only.
 */
#[Entity]
#[Table(name: 'ai_scan_runtime')]
class AiScanRuntime
{
    #[Id]
    #[Column(type: Types::SMALLINT)]
    public int $id = 1;

    #[Column(length: 8, options: ['default' => 'off'])]
    public string $mode = 'off';

    #[Column(options: ['default' => true])]
    public bool $adminOnly = true;

    #[Column(options: ['default' => 1])]
    public int $maxRunning = 1;

    #[Column(type: Types::BIGINT, options: ['default' => 20000000])]
    public int $dailyCeilingMicroUsd = 20_000_000;

    #[Column(type: Types::BIGINT, options: ['default' => 5000000])]
    public int $projectDailyMicroUsd = 5_000_000;

    #[Column(options: ['default' => 10])]
    public int $dailyStartsUser = 10;

    #[Column(options: ['default' => 20])]
    public int $dailyStartsProject = 20;

    #[Column(length: 8, options: ['default' => 'ok'])]
    public string $providerState = 'ok';

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $providerCheckedAt = null;

    #[Column(length: 64, nullable: true)]
    public null|string $providerError = null;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $scannerSeenAt = null;

    #[Column(length: 32, nullable: true)]
    public null|string $scannerVersion = null;

    #[Column(options: ['default' => false])]
    public bool $scannerTooOld = false;

    #[Column(options: ['default' => 0])]
    public int $consecutiveFailures = 0;

    #[Column(length: 32, nullable: true)]
    public null|string $failureClass = null;
}
