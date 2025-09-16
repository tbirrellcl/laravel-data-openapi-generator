<?php

namespace Xolvio\OpenApiGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as FacadeRoute;
use Xolvio\OpenApiGenerator\Data\OpenApi;

class GenerateOpenApiCommand extends Command
{
    protected $signature   = 'openapi:generate {--route-name=* : Filter the generated documentation to specific route names}';
    protected $description = 'Generates the OpenAPI documentation';

    public function handle(): int
    {
        $openapi = OpenApi::fromRoutes($this->getRoutes(), $this);

        $location  = config('openapi-generator.path');
        $directory = dirname($location);

        if (! File::isDirectory($directory)) {
            File::makeDirectory(
                path: dirname($location),
                recursive: true,
            );
        }

        File::put(
            $location,
            $openapi->toJson(JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        $this->info("OpenAPI documentation generated at {$location}");

        return Command::SUCCESS;
    }

    /**
     * @return array<string,array<string,Route>>
     */
    protected function getRoutes(): array
    {
        /** @var array<string,array<string,Route>> */
        $routes = [];

        /** @var string[] $route_name_filters */
        $route_name_filters = $this->getRouteNameFilters();

        /** @var array<int,Route> */
        $initial_routes = array_values(array_filter(
            FacadeRoute::getRoutes()->getRoutes(),
            fn (Route $route) => $this->strStartsWith($route->getPrefix() ?? '', config('openapi-generator.included_route_prefixes', []))
                && ! $this->strStartsWith($route->getName() ?? '', config('openapi-generator.ignored_route_names', []))
                && $this->routeMatchesNameFilters($route, $route_name_filters),
        ));

        foreach ($initial_routes as $route) {
            $uri = '/' . $route->uri;

            if (! key_exists($uri, $routes)) {
                $routes[$uri] = [];
            }

            /** @var string $method */
            foreach ($route->methods as $method) {
                $method = strtolower($method);
                if (in_array($method, config('openapi-generator.ignored_methods', []), true)) {
                    continue;
                }

                $this->info("Found route {$method} {$route->getName()} {$uri}");

                $routes[$uri][$method] = $route;
            }
        }

        return $routes;
    }

    /**
     * @return string[]
     */
    protected function getRouteNameFilters(): array
    {
        $filters = $this->option('route-name');

        if (null === $filters) {
            return [];
        }

        $filters = array_map(
            static fn (string $filter): string => rtrim(trim($filter), '.'),
            array_filter((array) $filters, static fn ($filter) => is_string($filter) && $filter !== ''),
        );

        return array_values(array_filter($filters, static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param string[] $filters
     */
    protected function routeMatchesNameFilters(Route $route, array $filters): bool
    {
        if (count($filters) === 0) {
            return true;
        }

        $route_name = $route->getName();

        if ($route_name === null) {
            return false;
        }

        foreach ($filters as $filter) {
            if ($route_name === $filter || str_starts_with($route_name, $filter . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string|string[] $needles
     */
    protected function strStartsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ('' !== (string) $needle && str_starts_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
