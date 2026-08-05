<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Security;

use Attribute;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Declares WHICH {@see McpScope} a token must carry to see and call the MCP
 * tools of the annotated class.
 *
 * ## Why an attribute and not a registry
 *
 * The scope belongs to the tool the way its input schema does: co-located, so
 * a tool and its permission cannot drift apart in a review, and so adding a
 * tool is one file rather than one file plus an entry in a list somebody has to
 * remember. {@see \WBoost\Web\DependencyInjection\McpToolScopePass} folds every
 * declaration into a container parameter at COMPILE time, so the runtime gate
 * is an array lookup — no reflection per tool call.
 *
 * ## Class-level, and what that means for multi-tool classes
 *
 * The attribute targets the CLASS, and covers every `#[McpTool]` method on it.
 * A class whose tools would need DIFFERENT scopes has to be split — which is
 * the honest outcome: "this file is `templates:design`" is a property a
 * reviewer can check at a glance, "this file is design except for that one
 * method" is not.
 *
 * ## Omitting it denies the tool — loudly
 *
 * There is no default. A tool class without this attribute is callable by
 * NOBODY (not even a token holding every scope) and appears in NO `tools/list`
 * — see {@see McpToolGate}. That failure mode is deliberate and self-revealing:
 * the tool is simply missing the first time it is exercised, instead of
 * silently becoming the one tool a read-only token can use.
 *
 * `#[Exclude]` keeps `config/services.php`'s `src/Mcp/{Tool,Design,Security}/`
 * directory load from registering this attribute class as a service — it is a
 * value, and autowiring its `McpScope` argument is not a thing that can work.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Exclude]
readonly final class McpToolScope
{
    public function __construct(
        public McpScope $scope,
    ) {
    }
}
