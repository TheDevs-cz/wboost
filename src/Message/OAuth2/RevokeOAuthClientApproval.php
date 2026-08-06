<?php

declare(strict_types=1);

namespace WBoost\Web\Message\OAuth2;

use Ramsey\Uuid\UuidInterface;

/**
 * Disconnect an application: forget the user's approval AND kill the
 * credentials it issued.
 *
 * Both halves are the point. A "revoke" that only removes the approval leaves
 * an access token valid for up to an hour and a refresh token valid for a
 * month — i.e. it tells the user they are safe at exactly the moment they are
 * not. They happen in one handler so they cannot be done apart, and inside the
 * `command_bus` transaction so they cannot half-happen.
 *
 * Idempotent: revoking an app that is not connected (or was already
 * disconnected) still revokes any credentials that somehow outlived the
 * approval row.
 */
readonly final class RevokeOAuthClientApproval
{
    public function __construct(
        public UuidInterface $userId,
        public string $clientIdentifier,
    ) {
    }
}
