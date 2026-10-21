<?php

namespace Webkul\Ondc\Providers;

use Illuminate\Support\Facades\Event;
use Konekt\Concord\BaseModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    public function boot()
    {
        parent::boot();

        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/../Routes/admin-routes.php');
        $this->loadRoutesFrom(__DIR__ . '/../Routes/ondc.php');
        $this->loadRoutesFrom(__DIR__ . '/../Routes/buyer-routes.php');

        // Load views
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'ondc');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
   public function register()
{
    parent::register();

    $this->mergeConfigFrom(
        __DIR__ . '/../Config/menu.php',
        'menu.admin'
    );

    $this->mergeConfigFrom(
        __DIR__ . '/../Config/domains.php',
        'ondc.domains'
    );

    $this->mergeConfigFrom(
        __DIR__ . '/../Config/taxonomy.php',
        'ondc.taxonomy'
    );
}
}
