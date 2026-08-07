<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use WBoost\Web\Mcp\Security\McpScope;

/**
 * One line of the "co s tím jde dělat" list on the AI (MCP server) page: a job
 * an AI client can do, named the way a brand manager would name it.
 *
 * A pure view value — built by
 * {@see \WBoost\Web\Services\Mcp\DescribeMcpCapabilities} from the tools that
 * are actually REGISTERED, rendered by `mcp_guide.html.twig`, never persisted.
 *
 * {@see $tool} is carried alongside the prose on purpose: it is the string a
 * user sees in their client's tool list and in an error message, so a page that
 * hid it would leave them unable to match the two.
 */
readonly final class McpCapability
{
    /**
     * @param string          $tool        the registered MCP tool name (`render_variant`)
     * @param string          $title       short Czech label — the job, not the tool
     * @param string          $description one or two Czech sentences of CONSEQUENCE
     * @param null|McpScope   $scope       the permission a client needs for it, null when the
     *                                     tool declares none (it is then callable by nobody —
     *                                     see {@see \WBoost\Web\Mcp\Security\McpToolGate})
     * @param null|string     $scopeTitle  Czech title of that permission, from the single
     *                                     source the consent screen uses
     * @param bool            $known       false = the tool is registered but this page has no
     *                                     Czech copy for it; the UI must say so rather than
     *                                     quietly drop a capability the client will offer
     */
    public function __construct(
        public string $tool,
        public string $title,
        public string $description,
        public null|McpScope $scope,
        public null|string $scopeTitle,
        public bool $known = true,
    ) {
    }
}
