<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Security;

/**
 * What an MCP personal access token is allowed to reach.
 *
 * Scopes are **not** a permission model — the authenticator resolves a real
 * {@see \WBoost\Web\Entity\User} and the ordinary voters keep deciding what
 * that user may touch. A scope is a SECOND, NARROWING axis on top of that:
 * effective permission = role ∩ scope. A designer handing an agent a
 * `templates:read` token gets a read-only agent; the same token can never
 * widen what the designer themselves could do.
 *
 * The string values are the wire format: they are stored raw in
 * `mcp_access_token.scopes`, advertised in `scopes_supported` of the
 * `/.well-known/oauth-protected-resource` document and echoed back in the
 * `WWW-Authenticate` challenge — so they are OAuth 2.1 scope tokens (lowercase,
 * `resource:action`) and must not be renamed once a token carrying them exists.
 */
enum McpScope: string
{
    /** Read templates, variants, the gallery and low-resolution previews. */
    case TemplatesRead = 'templates:read';

    /** Produce a full-size, lossless PNG export. */
    case TemplatesExport = 'templates:export';

    /** Author designs: create templates/variants and write canvases. */
    case TemplatesDesign = 'templates:design';

    /** Upload new pictures into a project's gallery. */
    case GalleryWrite = 'gallery:write';

    /**
     * Every scope a token holding THIS one effectively carries — itself first,
     * then the transitive closure of the implications declared below.
     *
     * This is the single place implication is expressed, and it is expressed as
     * an EXPANSION rather than as pairwise "does A cover B" comparisons on
     * purpose: pairwise checks are what rot when a case is added. Two
     * properties follow from that, both deliberate:
     *
     * - **Transitive.** If a later scope implies {@see self::TemplatesDesign},
     *   it automatically also grants {@see self::TemplatesRead} — the chain is
     *   walked, not hard-coded one hop deep.
     * - **Total.** The `match` is exhaustive with no `default`, so adding a
     *   case fails PHPStan right here until its implications are stated. A new
     *   scope cannot silently arrive with undefined implications.
     *
     * The already-expanded guard also makes a cycle terminate rather than hang,
     * should someone ever declare one.
     *
     * @return list<self>
     */
    public function grants(): array
    {
        /** @var list<self> $granted */
        $granted = [];
        $queue = [$this];

        while ($queue !== []) {
            $scope = array_shift($queue);

            if (in_array($scope, $granted, true)) {
                continue;
            }

            $granted[] = $scope;

            $queue = array_merge($queue, match ($scope) {
                self::TemplatesDesign => [self::TemplatesRead],
                self::TemplatesExport => [self::TemplatesRead],
                self::TemplatesRead => [],
                self::GalleryWrite => [],
            });
        }

        return $granted;
    }

    /**
     * Every scope's wire value, in declaration order.
     *
     * The list a human input surface offers and an error message names — kept
     * here rather than re-derived at each call site, so a new case shows up in
     * the CLI help and in {@see \WBoost\Web\Exceptions\InvalidMcpScopes} the
     * moment it is declared.
     *
     * The `non-empty-list<non-empty-string>` is not decoration: OAuth value
     * objects (`League\…\ValueObject\Scope`) demand a `non-empty-string`, and
     * "at least one scope exists" is what
     * {@see \WBoost\Web\Services\OAuth2\DynamicClientRegistrar} relies on to
     * guarantee it never saves a client with an EMPTY scope list — the state
     * the bundle's default-scope listener overwrites with the blanket `api`
     * scope. Both facts follow from this being an enum with cases, so stating
     * them costs nothing and spares every caller a re-assertion.
     *
     * @return non-empty-list<non-empty-string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }

    /**
     * Parses the raw strings persisted on {@see \WBoost\Web\Entity\McpAccessToken}
     * into cases, **dropping anything this release does not understand** and
     * de-duplicating the rest.
     *
     * Dropping is the point: a scope string that a later release removed (or
     * that a hand-edited row invented) must degrade to "not granted", never
     * blow up an otherwise valid authenticated request. Nothing here expands
     * implications — that is {@see grants()}'s job, applied by the caller.
     *
     * @param list<string> $values
     *
     * @return list<self>
     */
    public static function fromStrings(array $values): array
    {
        /** @var list<self> $scopes */
        $scopes = [];

        foreach ($values as $value) {
            $scope = self::tryFrom($value);

            if ($scope === null || in_array($scope, $scopes, true)) {
                continue;
            }

            $scopes[] = $scope;
        }

        return $scopes;
    }
}
