<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Designates a root administrator when none is set.
 *
 * Installs that start without the compliance package (for example an
 * open-source install moved to a commercial tier) have no root administrator,
 * and admin management stays unavailable until one is chosen. When no user is
 * marked root and at least one active administrator exists, the active
 * administrator with the lowest id becomes root. Nothing changes when a root
 * administrator already exists or when there are no active administrators.
 * The root administrator can be changed at any time with
 * `php artisan df:root_admin`.
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
        if (!Schema::hasTable('user') || !Schema::hasColumn('user', 'is_root_admin')) {
            return;
        }

        if (DB::table('user')->where('is_root_admin', true)->exists()) {
            return;
        }

        $adminId = DB::table('user')
            ->where('is_sys_admin', true)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        if (null === $adminId) {
            return;
        }

        DB::table('user')->where('id', $adminId)->update(['is_root_admin' => true]);
    }

    /**
     * Reverse the migrations.
     *
     * The designation is left in place: it may since have been confirmed or
     * changed with df:root_admin, and removing it would leave admin management
     * unavailable again.
     *
     * @return void
     */
    public function down()
    {
    }
};
