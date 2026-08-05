<?php

declare(strict_types=1);

namespace WBoost\Web;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use WBoost\Web\DependencyInjection\McpToolScopePass;

class SymfonyApplicationKernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        // Collects #[McpToolScope] into a container parameter. Default type and
        // priority put it in the same phase as the MCP bundle's own McpPass,
        // which is where the `mcp.tool` tags exist (attribute autoconfiguration
        // has run, and nothing removes the tags afterwards).
        $container->addCompilerPass(new McpToolScopePass());
    }
}
