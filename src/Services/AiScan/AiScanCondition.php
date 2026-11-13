<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use WBoost\Web\Value\AiScan\AiScanAlertCode;

/**
 * One operating condition as evaluated at one moment.
 */
#[Exclude]
final readonly class AiScanCondition
{
    /**
     * @param null|DateTimeImmutable $since since when it holds, when the database knows; otherwise the alert row remembers when it was first seen
     * @param array<string, int|string> $facts codes and numbers for the mail — never a design's text
     */
    public function __construct(
        public AiScanAlertCode $code,
        public bool $active,
        public null|DateTimeImmutable $since = null,
        public array $facts = [],
    ) {
    }
}
