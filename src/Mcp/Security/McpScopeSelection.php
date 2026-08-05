<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Security;

use WBoost\Web\Exceptions\InvalidMcpScopes;

/**
 * Turns a human-typed `templates:read,templates:export` into the raw scope list
 * a {@see \WBoost\Web\Message\Mcp\CreateMcpAccessToken} carries.
 *
 * **Strict on purpose — that is the entire difference from
 * {@see McpScope::fromStrings()}**, which looks like the same parse and is not.
 * `fromStrings()` reads STORED rows and silently drops what it does not
 * understand, because a scope string a later release removed must degrade to
 * "not granted" rather than break an otherwise valid token. This one reads a
 * person at a terminal, where `--scopes=templates:reed` is a typo: issuing a
 * token with fewer powers than asked for is the worst possible answer, since
 * the mistake only surfaces days later as an agent that mysteriously cannot
 * export. So an unknown value throws here and is dropped there. Do not "unify"
 * the two.
 *
 * Only the input is validated; implications are NOT expanded — `grants()`
 * evaluates those at check time, so the stored row keeps exactly what was
 * asked for.
 */
final class McpScopeSelection
{
    /**
     * What a token gets when `--scopes` is omitted: read-only. A token created
     * in a hurry must not be able to author designs.
     *
     * Held in the input format (comma-separated) so the console option can use
     * it as its default verbatim and no second literal exists to drift.
     */
    public const string DEFAULT_SCOPES = McpScope::TemplatesRead->value;

    /**
     * @return list<string> valid scope values, de-duplicated, in the order given
     *
     * @throws InvalidMcpScopes
     */
    public static function parse(string $input): array
    {
        /** @var list<string> $scopes */
        $scopes = [];

        foreach (explode(',', $input) as $candidate) {
            $candidate = trim($candidate);

            // An empty segment is typing noise ("a,,b", a trailing comma), not
            // an attempt to name a scope — skipped rather than reported. What
            // it must never do is silently produce an EMPTY selection, which is
            // why the guard below looks at the result, not at the input.
            if ($candidate === '') {
                continue;
            }

            $scope = McpScope::tryFrom($candidate);

            if ($scope === null) {
                throw InvalidMcpScopes::unknownScope($candidate);
            }

            if (in_array($scope->value, $scopes, true)) {
                continue;
            }

            $scopes[] = $scope->value;
        }

        if ($scopes === []) {
            throw InvalidMcpScopes::noScopeGiven();
        }

        return $scopes;
    }
}
