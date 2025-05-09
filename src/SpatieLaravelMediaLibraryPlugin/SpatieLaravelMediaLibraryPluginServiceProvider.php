<?php

namespace Filament\SpatieLaravelMediaLibraryPlugin;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SpatieLaravelMediaLibraryPluginServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Регистрация сервисов и биндингов, если необходимо
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Загрузка маршрутов, представлений, миграций и т.д.
        $this->loadTranslationsFrom(__DIR__ . '/../../resources/lang', 'spatie-laravel-media-library-plugin');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../resources/lang' => resource_path('lang/vendor/spatie-laravel-media-library-plugin'),
            ], 'spatie-laravel-media-library-plugin-lang');
        }
    }
}
