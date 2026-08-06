<?php

declare(strict_types=1);

namespace WBoost\Web\Message\OAuth2;

use Ramsey\Uuid\UuidInterface;

/**
 * The user pressed "Povolit přístup" on the consent screen: remember that this
 * user agreed to let this OAuth2 client do these things.
 *
 * `$scopes` must be the EFFECTIVE set — the requested scopes with implications
 * expanded, i.e. exactly the lines the consent screen displayed
 * ({@see \WBoost\Web\Services\OAuth2\DescribeConsentScopes::effectiveValues()}).
 * Storing the literal request instead would leave the stored approval narrower
 * than the grant it authorised, and every later coverage check would then
 * re-prompt for a scope the user had in fact already seen.
 */
readonly final class ApproveOAuthClient
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public UuidInterface $approvalId,
        public UuidInterface $userId,
        public string $clientIdentifier,
        public array $scopes,
    ) {
    }
}
