<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Security;

use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\InvalidCursorException;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Page;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Decorates the SDK's `mcp.registry` so the tool CATALOGUE is per-token.
 *
 * ## Why this seam
 *
 * `Mcp\Capability\RegistryInterface` is the one object both halves of the tool
 * protocol consult: `Mcp\Server\Handler\Request\ListToolsHandler` calls
 * {@see getTools()} and `Mcp\Server\Handler\Request\CallToolHandler` calls
 * {@see getTool()}. Everything else in the SDK (the `Builder`, the reference
 * handler, the pagination) goes through the same interface, and the bundle
 * passes the `mcp.registry` SERVICE into the builder — so decorating that one
 * id reaches every tool read with no forks of the SDK's own handlers.
 *
 * ## What it does, and what it deliberately does not
 *
 * - {@see getTools()} — filtered. A `templates:read` token does not learn that
 *   the design tools exist.
 * - {@see getTool()} — refused with `ToolNotFoundException`, i.e. the SDK's
 *   `method not found`. This is a BACKSTOP, not the gate: the real refusal is
 *   {@see ScopeGuardedMcpController}'s HTTP 403, which runs before any of this
 *   and does not consult the listing. This layer exists so that a tool stays
 *   un-executable even if a future transport (stdio, an in-process client, a
 *   batch shape the guard's parser does not recognise) ever reaches the
 *   registry without passing the HTTP guard.
 * - {@see hasTools()} — NOT filtered, on purpose. It feeds the SDK's
 *   `detectCapabilities()` at server BUILD time, i.e. once per built `Server`
 *   object — which under FrankenPHP's worker mode is once per worker, shared by
 *   every token that worker serves. Making a worker-wide, cached boolean depend
 *   on the first request's token would be both wrong and non-deterministic. It
 *   advertises the protocol capability "this server has tools", never which
 *   ones, so leaving it global leaks nothing.
 * - {@see hasTool()} — NOT filtered: it answers "is this registered", which is
 *   a question about the application, not about the caller.
 *
 * ## Pagination is re-implemented rather than delegated
 *
 * The inner registry paginates over the UNFILTERED set, so asking it for a page
 * and filtering afterwards would produce short pages, holes and a `nextCursor`
 * counting tools the caller cannot see. The full set is fetched, filtered, and
 * then sliced with the SDK's own cursor grammar (base64 of the offset), so a
 * client's cursor round-trips unchanged.
 *
 * ## On the CLI
 *
 * There is no security token on a console command, so `bin/console debug:mcp`
 * lists nothing once tools exist. That is the fail-closed rule doing its job;
 * `bin/console debug:container --tag=mcp.tool` is the CLI-side inventory.
 */
#[AsDecorator('mcp.registry')]
final readonly class ScopeFilteredToolRegistry implements RegistryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private RegistryInterface $inner,
        private McpToolGate $gate,
    ) {
    }

    public function getTools(null|int $limit = null, null|string $cursor = null): Page
    {
        /** @var array<string, Tool> $tools */
        $tools = [];

        foreach ($this->inner->getTools()->references as $tool) {
            if (!$tool instanceof Tool || !$this->gate->allows($tool->name)) {
                continue;
            }

            $tools[$tool->name] = $tool;
        }

        // Mirrors the inner registry: an unbounded read keeps the name-keyed
        // shape, a bounded one returns a positional slice.
        if ($limit === null) {
            return new Page($tools, null);
        }

        $offset = $this->offset($cursor, count($tools));
        $nextOffset = $offset + $limit;

        return new Page(
            array_values(array_slice($tools, $offset, $limit)),
            $nextOffset < count($tools) ? base64_encode((string) $nextOffset) : null,
        );
    }

    public function getTool(string $name): ToolReference
    {
        // Resolve first, so an unknown name keeps reporting as unknown rather
        // than as forbidden.
        $reference = $this->inner->getTool($name);

        if (!$this->gate->allows($name)) {
            throw new ToolNotFoundException($name);
        }

        return $reference;
    }

    /**
     * The SDK's cursor grammar: base64 of a decimal offset. Anything else is an
     * invalid cursor — same exception the inner registry raises, so the JSON-RPC
     * error a client sees is unchanged.
     */
    private function offset(null|string $cursor, int $total): int
    {
        if ($cursor === null) {
            return 0;
        }

        $decoded = base64_decode($cursor, true);

        if ($decoded === false || !is_numeric($decoded)) {
            throw new InvalidCursorException($cursor);
        }

        $offset = (int) $decoded;

        if ($offset < 0 || $offset > $total) {
            throw new InvalidCursorException($cursor);
        }

        return $offset;
    }

    public function registerTool(Tool $tool, callable|array|string $handler): ToolReference
    {
        return $this->inner->registerTool($tool, $handler);
    }

    public function registerResource(ResourceDefinition $resource, callable|array|string $handler): ResourceReference
    {
        return $this->inner->registerResource($resource, $handler);
    }

    public function registerResourceTemplate(
        ResourceTemplate $template,
        callable|array|string $handler,
        array $completionProviders = [],
    ): ResourceTemplateReference {
        return $this->inner->registerResourceTemplate($template, $handler, $completionProviders);
    }

    public function registerPrompt(
        Prompt $prompt,
        callable|array|string $handler,
        array $completionProviders = [],
    ): PromptReference {
        return $this->inner->registerPrompt($prompt, $handler, $completionProviders);
    }

    public function unregisterTool(string $name): void
    {
        $this->inner->unregisterTool($name);
    }

    public function unregisterResource(string $uri): void
    {
        $this->inner->unregisterResource($uri);
    }

    public function unregisterResourceTemplate(string $uriTemplate): void
    {
        $this->inner->unregisterResourceTemplate($uriTemplate);
    }

    public function unregisterPrompt(string $name): void
    {
        $this->inner->unregisterPrompt($name);
    }

    public function hasTool(string $name): bool
    {
        return $this->inner->hasTool($name);
    }

    public function hasResource(string $uri): bool
    {
        return $this->inner->hasResource($uri);
    }

    public function hasResourceTemplate(string $uriTemplate): bool
    {
        return $this->inner->hasResourceTemplate($uriTemplate);
    }

    public function hasPrompt(string $name): bool
    {
        return $this->inner->hasPrompt($name);
    }

    public function hasTools(): bool
    {
        return $this->inner->hasTools();
    }

    public function hasResources(): bool
    {
        return $this->inner->hasResources();
    }

    public function getResources(null|int $limit = null, null|string $cursor = null): Page
    {
        return $this->inner->getResources($limit, $cursor);
    }

    public function getResource(string $uri, bool $includeTemplates = true): ResourceReference|ResourceTemplateReference
    {
        return $this->inner->getResource($uri, $includeTemplates);
    }

    public function hasResourceTemplates(): bool
    {
        return $this->inner->hasResourceTemplates();
    }

    public function getResourceTemplates(null|int $limit = null, null|string $cursor = null): Page
    {
        return $this->inner->getResourceTemplates($limit, $cursor);
    }

    public function getResourceTemplate(string $uriTemplate): ResourceTemplateReference
    {
        return $this->inner->getResourceTemplate($uriTemplate);
    }

    public function hasPrompts(): bool
    {
        return $this->inner->hasPrompts();
    }

    public function getPrompts(null|int $limit = null, null|string $cursor = null): Page
    {
        return $this->inner->getPrompts($limit, $cursor);
    }

    public function getPrompt(string $name): PromptReference
    {
        return $this->inner->getPrompt($name);
    }
}
