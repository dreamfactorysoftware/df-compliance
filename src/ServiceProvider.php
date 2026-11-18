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
use DreamFactory\Core\Compliance\Http\Middleware\DoesRootAdminExist;
use DreamFactory\Core\Compliance\Http\Middleware\HandleRestrictedAdminRole;
use DreamFactory\Core\Compliance\Http\Middleware\MarkAsRootAdmin;
use DreamFactory\Core\Compliance\Http\Middleware\ServiceLevelAudit;
use DreamFactory\Core\Compliance\Models\AdminUser;
use DreamFactory\Core\Models\User;
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
        $this->addAdminGuard();
    }

    /**
     * Check admin account changes on the model, not only on the URL. This
     * covers admin accounts, restricted admin roles and the records that
     * belong to them. Operator console commands (setup, the root admin
     * command) skip the check; other console work does not. Scheduled tasks
     * that run as another admin are checked when they are saved.
     *
     * @return void
     */
    protected function addAdminGuard()
    {
        foreach (['saving' => false, 'deleting' => true] as $event => $deleting) {
            Event::listen("eloquent.$event: *", function ($name, array $data) use ($deleting) {
                $model = $data[0] ?? null;
                if ($model instanceof User) {
                    if (!AdminUser::isOperatorConsole()) {
                        AdminUser::authorizeChange($model, $deleting);
                    }
                } elseif (AdminUser::isAdminRelatedModel($model) && !AdminUser::isOperatorConsole()) {
                    AdminUser::authorizeRelatedChange($model);
                }
            });
        }

        // A scheduled task can run as a chosen user (df-scheduler run-as).
        Event::listen('eloquent.saving: ' . AdminUser::SCHEDULER_TASK_MODEL, function ($task) {
            if (!AdminUser::isOperatorConsole()) {
                AdminUser::authorizeRunAs($task);
            }
        });
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
        Route::aliasMiddleware('df.mark_root_admin', MarkAsRootAdmin::class);
        Route::aliasMiddleware('df.does_root_admin_exist', DoesRootAdminExist::class);
        Route::aliasMiddleware('df.handle_restricted_admin', HandleRestrictedAdmin::class);
        Route::aliasMiddleware('df.service_level_audit', ServiceLevelAudit::class);
        Route::aliasMiddleware('df.accessible_tabs', AccessibleTabs::class);
        Route::aliasMiddleware('df.handle_restricted_admin_role', HandleRestrictedAdminRole::class);

        Route::pushMiddlewareToGroup('df.api', 'df.mark_root_admin');
        Route::pushMiddlewareToGroup('df.api', 'df.does_root_admin_exist');
        Route::pushMiddlewareToGroup('df.api', 'df.handle_restricted_admin');
        Route::pushMiddlewareToGroup('df.api', 'df.accessible_tabs');
        Route::pushMiddlewareToGroup('df.api', 'df.handle_restricted_admin_role');
        Route::pushMiddlewareToGroup('df.api', 'df.service_level_audit');
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
