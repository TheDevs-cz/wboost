<?php

declare(strict_types=1);

namespace WBoost\Web\Message\AiScan;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\AiScan\AiScanChannel;
use WBoost\Web\Value\AiScan\AiScanKind;

/**
 * Start a scan from an uploaded picture. `requestId` makes it idempotent: the
 * same key of the same user answers with the first scan. The handler returns
 * the id of the scan the request stands for (read it from the `HandledStamp`).
 */
readonly final class RequestAiScan
{
    /**
     * @param string $filePath a local file (the upload's temporary file)
     * @param array<string, mixed> $options
     */
    public function __construct(
        public UuidInterface $scanId,
        public string $requestId,
        public UuidInterface $projectId,
        public UuidInterface $userId,
        public AiScanKind $kind,
        public AiScanChannel $channel,
        public string $filePath,
        public string $originalName,
        public array $options,
        public bool $consentGiven,
        public null|UuidInterface $calendarId = null,
    ) {
    }
}
