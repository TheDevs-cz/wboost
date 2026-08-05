<?php

declare(strict_types=1);

namespace WBoost\Web\DependencyInjection;

use Mcp\Capability\Attribute\McpTool;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;

/**
 * Folds every `#[McpToolScope]` declaration into ONE container parameter —
 * `tool name => scope value | null` — so the runtime gate
 * ({@see \WBoost\Web\Mcp\Security\McpToolGate}) is an array lookup rather than
 * reflection on every `tools/list` and every `tools/call`.
 *
 * ## Why compile time
 *
 * The MCP bundle already reflects each `#[McpTool]` method once at compile time
 * (its `McpPass` derives the JSON input schema that way) and caches the result
 * in the dumped container. Doing the same for scopes costs nothing extra at
 * runtime and keeps the two facts about a tool — its schema and its
 * permission — resolved at the same moment, from the same reflection pass.
 *
 * ## The map is keyed by TOOL NAME, and that name is derived exactly as the SDK
 * derives it
 *
 * The gate only ever learns a tool NAME (`params.name` of a `tools/call`, and
 * `Tool::$name` in a listing), so the name is the key. The SDK's
 * `ReflectedElementLoader` resolves it as `#[McpTool(name:)]` when given, else
 * the method name — or the class short name for an `__invoke()` tool. That rule
 * is mirrored below and is the ONE thing here that could silently drift if the
 * SDK changed it. It cannot drift silently for long: a key that no longer
 * matches a registered tool makes that tool invisible and uncallable for
 * EVERYONE (fail closed), which `tests/Mcp/ScopeFilteringTest.php` asserts
 * against by name.
 *
 * ## Entries with a null scope are NOT missing entries
 *
 * A tool that is registered but carries no `#[McpToolScope]` is recorded with a
 * `null` value, not skipped. The distinction is load-bearing: an ABSENT key
 * means "not an MCP tool at all" (the gate lets those through, so a typo'd tool
 * name still gets the SDK's honest "method not found"), while a `null` value
 * means "a real tool that declares no scope" — denied to everybody.
 */
final class McpToolScopePass implements CompilerPassInterface
{
    /**
     * `array<string, string|null>` — tool name => {@see \WBoost\Web\Mcp\Security\McpScope}
     * value, or null when the tool declares no scope.
     */
    public const string PARAMETER = 'wboost.mcp.tool_scopes';

    /**
     * The tag the MCP bundle's attribute autoconfiguration puts on every
     * `#[McpTool]` service, carrying the `method` that holds the attribute.
     */
    private const string TAG = 'mcp.tool';

    public function process(ContainerBuilder $container): void
    {
        /** @var array<string, null|string> $toolScopes */
        $toolScopes = [];

        foreach ($container->findTaggedServiceIds(self::TAG) as $serviceId => $tags) {
            $definition = $container->getDefinition($serviceId);

            if ($definition->isAbstract()) {
                continue;
            }

            $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $serviceId);

            // A tag that maps to no real class is the bundle's problem to
            // report (its McpPass throws on exactly that); silently skipping it
            // here only means the tool never reaches the map — i.e. denied.
            if (!is_string($class) || !class_exists($class)) {
                continue;
            }

            $scope = $this->declaredScope($class);

            foreach ($tags as $tagAttributes) {
                // Attribute autoconfiguration always records the method; a
                // hand-written tag may not, and the bundle then defaults to
                // __invoke().
                $method = is_array($tagAttributes) ? $tagAttributes['method'] ?? '__invoke' : '__invoke';

                if (!is_string($method) || !method_exists($class, $method)) {
                    continue;
                }

                $name = $this->toolName($class, $method);

                if ($name === null) {
                    continue;
                }

                $toolScopes[$name] = $scope?->value;
            }
        }

        // Sorted so the dumped container is stable across builds.
        ksort($toolScopes);

        $container->setParameter(self::PARAMETER, $toolScopes);
    }

    /**
     * The scope declared by the tool CLASS, or null when it declares none.
     * Attributes are not inherited: a base class cannot lend its scope to a
     * subclass, because "which permission does this file need" must be readable
     * in the file itself.
     *
     * @param class-string $class
     */
    private function declaredScope(string $class): null|McpScope
    {
        $attributes = (new ReflectionClass($class))->getAttributes(McpToolScope::class, ReflectionAttribute::IS_INSTANCEOF);

        return $attributes === [] ? null : $attributes[0]->newInstance()->scope;
    }

    /**
     * The name the SDK will register this tool under — see the class docblock.
     *
     * @param class-string $class
     */
    private function toolName(string $class, string $method): null|string
    {
        $reflection = new ReflectionMethod($class, $method);

        $attributes = $reflection->getAttributes(McpTool::class, ReflectionAttribute::IS_INSTANCEOF);

        // Mirrors the bundle's McpPass: a class-level #[McpTool] only applies to
        // __invoke().
        if ($attributes === [] && $method === '__invoke') {
            $attributes = $reflection->getDeclaringClass()->getAttributes(McpTool::class, ReflectionAttribute::IS_INSTANCEOF);
        }

        if ($attributes === []) {
            return null;
        }

        $tool = $attributes[0]->newInstance();

        if ($tool->name !== null) {
            return $tool->name;
        }

        return $method === '__invoke'
            ? $reflection->getDeclaringClass()->getShortName()
            : $method;
    }
}
