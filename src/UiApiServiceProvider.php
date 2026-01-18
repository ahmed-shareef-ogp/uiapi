<?php

namespace Ogp\UiApi;

use Illuminate\Support\ServiceProvider;

class UiApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/uiapi.php', 'uiapi');
    }

    public function boot(): void
    {
        // Keep ComponentConfigs inside the package; do not publish.
        // ViewConfigs: optionally publish stubs so apps can edit them.
        $this->publishes([
            __DIR__.'/../resources/viewConfigs' => base_path('app/Services/viewConfigs'),
        ], 'uiapi-view-configs');

        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
    }
}
