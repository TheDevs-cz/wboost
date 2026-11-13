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
 * One row per operating condition admins are e-mailed about
 * ({@see \WBoost\Web\Value\AiScan\AiScanAlertCode}): since when it lasts,
 * when it was announced, when the last mail about it went out and when it
 * ended. It is what keeps an outage from flooding the admins — one mail when
 * a condition opens, one reminder a day while it lasts, one when it ends.
 *
 * Read and written by {@see \WBoost\Web\Services\AiScan\AiScanAlerts} (raw
 * SQL, conditional updates: whoever changes the row sends the mail); this
 * class is the schema.
 */
#[Entity]
#[Table(name: 'ai_scan_alert')]
class AiScanAlert
{
    #[Id]
    #[Column(length: 32)]
    public string $code;

    /** Since when the condition lasts, as far as known. Null = it does not. */
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $activeSince = null;

    /** When it was announced. Null = no mail about it is out. */
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $openedAt = null;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $lastSentAt = null;

    /** When an announced condition last ended. */
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $clearedAt = null;

    public function __construct(string $code)
    {
        $this->code = $code;
    }
}
