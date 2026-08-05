<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    // Registers `_mcp_endpoint` at mcp.http.path via the bundle's RouteLoader.
    // The loader ignores the resource and keys purely off the `mcp` type; it
    // returns an empty collection while the http client transport is disabled.
    $routes->import('.', 'mcp');
};
