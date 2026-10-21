<?php

namespace Webkul\Ondc\Providers;

use Illuminate\Support\ServiceProvider;

class OndcServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'ondc');
        $this->loadViewsFrom(__DIR__ . '/../Resources/views/vendor', 'admin');

        $this->loadRoutesFrom(__DIR__ . '/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');
        $this->loadRoutesFrom(__DIR__ . '/../Routes/ondc.php');
        $this->loadRoutesFrom(__DIR__ . '/../Routes/buyer-routes.php');
        $this->loadRoutesFrom(__DIR__ . '/../Routes/logistics.php');

        // NOTE: admin-routes.php exists in this package but was never
        // loaded here — confirm whether that's intentional. If your
        // admin ONDC screens (product confirmation, order sync, etc.)
        // aren't working, this is likely why.
         $this->loadRoutesFrom(__DIR__ . '/../Routes/admin-routes.php');
    }

    /**
     * Register services.
     */
    public function register(): void
    {
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