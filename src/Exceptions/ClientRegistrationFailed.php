<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * An RFC 7591 dynamic client registration was refused.
 *
 * The `error` values are the ones RFC 7591 §3.2.2 defines and NOTHING else —
 * a registration endpoint that invents its own codes is one a conformant client
 * cannot tell "your redirect URI is wrong" from "your metadata is wrong", and
 * both are things the client can fix by retrying with different values.
 *
 * `unapproved_software_statement` is the fourth code in that list and is
 * deliberately absent: it means "the statement is valid but we will not honour
 * it", which presupposes we verify statements at all. We do not — see
 * {@see \WBoost\Web\Services\OAuth2\DynamicClientRegistrar::assertNoSoftwareStatement()}.
 *
 * No `#[WithHttpStatus]`: every one of these is a 400 and the controller says
 * so once, together with the `Cache-Control: no-store` the RFC requires on the
 * same response. An attribute would put half of that contract somewhere else.
 */
final class ClientRegistrationFailed extends \Exception
{
    /** RFC 7591 §3.2.2 — "The value of one or more redirection URIs is invalid." */
    public const string INVALID_REDIRECT_URI = 'invalid_redirect_uri';

    /** RFC 7591 §3.2.2 — "The value of one of the client metadata fields is invalid…" */
    public const string INVALID_CLIENT_METADATA = 'invalid_client_metadata';

    /** RFC 7591 §3.2.2 — "The software statement presented is invalid." */
    public const string INVALID_SOFTWARE_STATEMENT = 'invalid_software_statement';

    private function __construct(
        public readonly string $error,
        string $description,
    ) {
        parent::__construct($description);
    }

    public static function invalidRedirectUri(string $description): self
    {
        return new self(self::INVALID_REDIRECT_URI, $description);
    }

    public static function invalidClientMetadata(string $description): self
    {
        return new self(self::INVALID_CLIENT_METADATA, $description);
    }

    public static function invalidSoftwareStatement(string $description): self
    {
        return new self(self::INVALID_SOFTWARE_STATEMENT, $description);
    }
}
