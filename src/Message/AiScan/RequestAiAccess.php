<?php

declare(strict_types=1);

namespace WBoost\Web\Message\AiScan;

use Ramsey\Uuid\UuidInterface;

/**
 * "Požádat o přístup": a project editor asks for AI scan in a project that
 * does not have it. The handler answers `{id, created, sentAt}` — a second
 * request while one is open answers with the open one.
 */
readonly final class RequestAiAccess
{
    public function __construct(
        public UuidInterface $requestId,
        public UuidInterface $projectId,
        public UuidInterface $userId,
        public string $origin,
        public null|string $message,
    ) {
    }
}
