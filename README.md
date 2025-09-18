# OpenAPI Generator using Laravel Data

Generate OpenAPI specification from Laravel routes and Laravel Data objects.

# Install

## Add composer repository

In `composer.json` add this repository:

```json
    "repositories": [
        {
            "type": "github",
            "url": "https://github.com/MartinPham/laravel-data-openapi-generator"
        }
    ],
```

## Install

`composer require xolvion/laravel-data-openapi-generator`

# Optional

## Version

Add a `app.version` config in `app.php` to set the version in the openapi specification:
```php
    'version' => env('APP_VERSION', '1.0.0'),
```

## Vite PWA config

If using `vite-plugin-pwa`, make sure to exclude '/api/' routes from the serviceworker using this config:

```ts
VitePWA({
    workbox: {
        navigateFallbackDenylist: [
            new RegExp('/api/.+'),
        ],
    },
})
```

## Vue page

```vue
<route lang="json">
{
    "meta": {
        "public": true
    }
}
</route>

<template>
    <iframe
        :src="url"
        style="width: calc(100vw - 40px);height: calc(100vh - 80px); border: none;"
    />
</template>

<script lang="ts" setup>
const url = `${import.meta.env.VITE_APP_URL}/api/openapi`;
</script>
```

# Usage

## Config

`php artisan vendor:publish --tag=openapi-generator`

## Generate

`php artisan openapi:generate`

- Use `--route-name` to limit the generated document to a subset of named routes. The value may be a full route name or a dotted prefix:

  ```bash
  php artisan openapi:generate --route-name=api.posts --route-name=api.users.index
  ```

  The example above documents everything under `api.posts.*` and just the `api.users.index` route.

## View

Swagger available at `APP_URL/api/openapi`

You can publish the route stub and adjust it (for example to require auth, or move it under another prefix). If the published file exists it will be used instead of the package default.

## Ignoring routes intentionally

Annotate controller methods that should be skipped with `#[\Xolvio\OpenApiGenerator\Attributes\OpenApiIgnore]`.

```php
use Xolvio\OpenApiGenerator\Attributes\OpenApiIgnore;

#[OpenApiIgnore]
public function debugProbe() {}
```

## Error helpers

Return the provided error data objects (e.g. `InternalServerError`, `UnauthorizedError`, `TooManyRequestsError`) directly from your controllers to emit consistent JSON responses:

```php
use Xolvio\OpenApiGenerator\Errors\InternalServerError;

return new InternalServerError(
    error: 'Error performing search, please try again later',
    details: $exception->getMessage(),
);
```

Each error class sets the correct HTTP status via attributes, ensuring the OpenAPI document reflects the response accurately.

## Collections

Methods returning `DataCollection<T>` are now documented as arrays of the underlying data class. Add a return docblock such as `@return DataCollection<int,App\Data\ConversationData>` and the generator will expose an array schema whose `items` reference `ConversationData` rather than the collection wrapper.
