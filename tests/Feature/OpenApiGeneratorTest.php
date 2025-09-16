<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Xolvio\OpenApiGenerator\Test\Controller;

beforeAll(function () {
    if (File::exists(config('openapi-generator.path'))) {
        File::delete(config('openapi-generator.path'));
    }

    Route::prefix('api')->group(function () {
        Route::post('/{parameter_1}/{parameter_2}/{parameter_3}', [Controller::class, 'allCombined'])
            ->name('allCombined');
        Route::post('/contentType', [Controller::class, 'contentType'])
            ->name('contentType');
        Route::get('/auth', [Controller::class, 'basic'])
            ->can('permission1')
            ->middleware('can:permission2')
            ->middleware('auth:sanctum')
            ->name('auth');
        Route::get('/posts', [Controller::class, 'basic'])
            ->name('api.posts.index');
        Route::get('/posts/show', [Controller::class, 'basic'])
            ->name('api.posts.show');
        Route::get('/users', [Controller::class, 'basic'])
            ->name('api.users.index');
    });
});

it('can generate json', function () {
    Artisan::call('openapi:generate');

    expect(File::exists(config('openapi-generator.path')))->toBe(true);
    expect(File::get(config('openapi-generator.path')))->toBeJson();
});

it('can filter routes by name depth', function () {
    Artisan::call('openapi:generate', ['--route-name' => 'api.posts']);

    $openapi = json_decode(
        File::get(config('openapi-generator.path')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($openapi['paths'] ?? [])
        ->toHaveKey('/api/posts')
        ->toHaveKey('/api/posts/show')
        ->not->toHaveKey('/api/users')
        ->not->toHaveKey('/api/auth');
});

it('can filter routes by full name', function () {
    Artisan::call('openapi:generate', ['--route-name' => 'api.posts.index']);

    $openapi = json_decode(
        File::get(config('openapi-generator.path')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($openapi['paths'] ?? [])
        ->toHaveKey('/api/posts')
        ->not->toHaveKey('/api/posts/show')
        ->not->toHaveKey('/api/users');
});

afterAll(function () {
    if (File::exists(config('openapi-generator.path'))) {
        File::delete(config('openapi-generator.path'));
    }
});
