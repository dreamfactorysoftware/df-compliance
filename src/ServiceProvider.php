<?php

namespace DreamFactory\Core\Compliance;

use DreamFactory\Core\Compliance\Handlers\Events\EventHandler;
use DreamFactory\Core\Compliance\Http\Middleware\AccessibleTabs;
use DreamFactory\Core\Compliance\Http\Middleware\HandleRestrictedAdmin;
use Illuminate\Routing\Router;
use Route;
use Event;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    /**
     * @inheritdoc
     */
    public function boot()
    {
        // add migrations, https://laravel.com/docs/5.4/packages#resources
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Event::subscribe(new EventHandler());

        $this->addMiddleware();
    }

    /**
     * Register any middleware aliases.
     *
     * @return void
     */
    protected function addMiddleware()
    {
        // the method name was changed in Laravel 5.4
        if (method_exists(Router::class, 'aliasMiddleware')) {
            Route::aliasMiddleware('df.handle_restricted_admin', HandleRestrictedAdmin::class);
            Route::aliasMiddleware('df.accessible_tabs', AccessibleTabs::class);
        } else {
            /** @noinspection PhpUndefinedMethodInspection */
            Route::middleware('df.handle_restricted_admin', HandleRestrictedAdmin::class);
            Route::middleware('df.accessible_tabs', AccessibleTabs::class);
        }

        Route::pushMiddlewareToGroup('df.api', 'df.handle_restricted_admin');
        Route::pushMiddlewareToGroup('df.api', 'df.accessible_tabs');
    }
}
