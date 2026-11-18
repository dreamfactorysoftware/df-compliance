<?php

use DreamFactory\Core\Compliance\Components\RestrictedAdmin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Includes Server Settings for existing restricted admins with the Config tab.
 *
 * The Config tab now includes the Server Settings page (system/server_setting).
 * Restricted admins created before that keep the Config tab but only get the
 * Server Settings access when a root admin saves them again. This migration
 * does that save for every restricted admin whose role has the Config tab:
 * it calls RestrictedAdmin::updateRestrictedAdminRole() with the tabs the role
 * already has, the same call the admin save makes. Other role accesses are
 * left as they are.
 *
 * A role that already has a Server Settings access, in any form, is skipped,
 * so running the migration again changes nothing. A restricted admin whose
 * role could not be updated is logged and keeps working as before; saving
 * that admin again as a root admin includes Server Settings.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        foreach (['user', 'app', 'role', 'role_service_access', 'user_to_app_to_role'] as $table) {
            if (!Schema::hasTable($table)) {
                return;
            }
        }

        $adminAppId = DB::table('app')->where('name', 'admin')->value('id');
        if (null === $adminAppId) {
            return;
        }

        // Restricted admins: administrators assigned a role for the admin app. The admin
        // save finds that role by name ("<email>'s role"), so only matching roles are used.
        $admins = DB::table('user_to_app_to_role')
            ->join('user', 'user.id', '=', 'user_to_app_to_role.user_id')
            ->join('role', 'role.id', '=', 'user_to_app_to_role.role_id')
            ->where('user_to_app_to_role.app_id', $adminAppId)
            ->where('user.is_sys_admin', true)
            ->orderBy('user.id')
            ->get(['user.id as user_id', 'user.email', 'role.id as role_id', 'role.name as role_name']);

        $systemServiceId = null;
        $done = [];
        foreach ($admins as $admin) {
            $roleId = (int)$admin->role_id;
            if (isset($done[$roleId]) || $admin->role_name !== $admin->email . "'s role") {
                continue;
            }
            $done[$roleId] = true;

            try {
                if (null === $systemServiceId) {
                    $systemServiceId = ServiceManager::getServiceIdByName(RestrictedAdmin::SYSTEM_SERVICE_NAME);
                }
                $hasServerSettings = DB::table('role_service_access')
                    ->where('role_id', $roleId)
                    ->where('service_id', $systemServiceId)
                    ->where('component', 'server_setting/*')
                    ->exists();
                if ($hasServerSettings) {
                    continue;
                }

                $tabs = RestrictedAdmin::getAccessibleTabsByRoleId($roleId);
                // The admin save keeps a role only for some of the tabs, not all of them.
                if (!in_array('config', $tabs, true) || RestrictedAdmin::isAllTabs($tabs)) {
                    continue;
                }

                (new RestrictedAdmin($admin->email, $tabs))->updateRestrictedAdminRole();
            } catch (\Throwable $e) {
                Log::warning(
                    'Server Settings were not included for restricted admin ' . $admin->user_id .
                    '; save the admin again as a root admin to include them. ' . $e->getMessage()
                );
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * The Server Settings accesses stay: a root admin may have changed them
     * since, and saving the admin again would add them back.
     *
     * @return void
     */
    public function down()
    {
    }
};
