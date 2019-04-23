<?php

namespace DreamFactory\Core\Compliance;

use DreamFactory\Core\Compliance\Commands\RootAdmin as RootAdminCommand;
use DreamFactory\Core\Compliance\Handlers\Events\EventHandler;
use DreamFactory\Core\Compliance\Resources\System\ServiceReport;
use DreamFactory\Core\System\Components\SystemResourceManager;
use DreamFactory\Core\Enums\LicenseLevel;
use DreamFactory\Core\System\Components\SystemResourceType;
use DreamFactory\Core\Compliance\Http\Middleware\AccessibleTabs;
use DreamFactory\Core\Compliance\Http\Middleware\HandleRestrictedAdmin;
use DreamFactory\Core\Compliance\Http\Middleware\HandleRestrictedAdminRole;
use DreamFactory\Core\Compliance\Http\Middleware\MarkAsRootAdmin;
use DreamFactory\Core\Compliance\Http\Middleware\ServiceLevelAudit;
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
        $this->addCommands();
    }

    public function register(){
        $this->app->resolving('df.system.resource', function (SystemResourceManager $df) {
            $df->addType(
                new SystemResourceType([
                    'name'                  => 'service_report',
                    'label'                 => 'Service Reports',
                    'description'           => 'Allows management of service report(s).',
                    'class_name'            => ServiceReport::class,
                    'subscription_required' => LicenseLevel::GOLD,
                    'singleton'             => false,
                    'read_only'             => false,
                ])
            );
        });
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
            Route::aliasMiddleware('df.mark_root_admin', MarkAsRootAdmin::class);
            Route::aliasMiddleware('df.handle_restricted_admin', HandleRestrictedAdmin::class);
            Route::aliasMiddleware('df.accessible_tabs', AccessibleTabs::class);
            Route::aliasMiddleware('df.handle_restricted_admin_role', HandleRestrictedAdminRole::class);
        } else {
            /** @noinspection PhpUndefinedMethodInspection */
            Route::middleware('df.mark_root_admin', MarkAsRootAdmin::class);
            Route::middleware('df.handle_restricted_admin', HandleRestrictedAdmin::class);
            Route::middleware('df.accessible_tabs', AccessibleTabs::class);
            Route::middleware('df.handle_restricted_admin_role', HandleRestrictedAdminRole::class);
        }

        Route::pushMiddlewareToGroup('df.api', 'df.mark_root_admin');
        Route::pushMiddlewareToGroup('df.api', 'df.handle_restricted_admin');
        Route::pushMiddlewareToGroup('df.api', 'df.accessible_tabs');
        Route::pushMiddlewareToGroup('df.api', 'df.handle_restricted_admin_role');
    }

    /**
     * Register compliance commands.
     *
     * @return void
     */
    protected function addCommands()
    {
        $this->commands([
            RootAdminCommand::class,
        ]);
    }
}
