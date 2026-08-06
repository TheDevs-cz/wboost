<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use DateTimeImmutable;
use WBoost\Web\Value\ConsentScope;

/**
 * One row of the "Propojené aplikace" page: an application the user has
 * connected, what it may do, and whether it is still using that permission.
 *
 * A read-model DTO, not a service (excluded from the container in
 * `config/services.php`, like every other `src/Query` view value).
 */
readonly final class ConnectedApp
{
    /**
     * @param list<ConsentScope> $scopes         the stored approval, re-described in today's Czech
     * @param list<string>       $redirectHosts  hosts the client's codes are delivered to — the
     *                                           non-forgeable half of its identity
     * @param int                $liveTokenCount access tokens that are neither revoked nor expired;
     *                                           0 means the app currently holds no live session
     */
    public function __construct(
        public string $clientIdentifier,
        public string $clientName,
        public array $scopes,
        public DateTimeImmutable $approvedAt,
        public null|DateTimeImmutable $lastUsedAt,
        public array $redirectHosts,
        public int $liveTokenCount,
    ) {
    }
}
