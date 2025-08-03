<?php

namespace Xolvio\OpenApiGenerator;

use Illuminate\Support\ServiceProvider;
use Xolvio\OpenApiGenerator\Commands\GenerateOpenApiCommand;

class OpenApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/openapi-generator.php' => config_path('openapi-generator.php'),
            __DIR__ . '/resources/views' => resource_path('views/vendor/openapi-generator'),
            __DIR__ . '/routes/routes.php' => base_path('routes/openapi-generator.php'),
        ], 'openapi-generator');

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateOpenApiCommand::class,
            ]);
        }

        $publishedRoutesPath = base_path('routes/openapi-generator.php');
        if (file_exists($publishedRoutesPath)) {
            $this->loadRoutesFrom($publishedRoutesPath);
        } else {
            $this->loadRoutesFrom(__DIR__ . '/routes/routes.php');
        }
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'openapi-generator');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/openapi-generator.php',
            'openapi-generator'
        );
    }
}
