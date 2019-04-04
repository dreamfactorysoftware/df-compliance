<?php

namespace DreamFactory\Core\Compliance\Commands;

use DreamFactory\Core\Compliance\Models\AdminUser;
use Illuminate\Console\Command;

class RootAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'df:root_admin
                                {--admin_id= : Admin user id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set one of admins as root admin.';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $headers = ['Id', 'Email', 'Display Name', 'First Name', 'Last Name', 'Active', 'Root Admin', 'Registration'];

            $admins = $this->getAdmins();

            $this->info('**********************************************************************************************************************');
            $this->info('Admins');
            $this->table($headers, $admins);
            $this->info('**********************************************************************************************************************');

            $adminId = $this->option('admin_id');
            $adminExists = AdminUser::adminExistsById($adminId);

            if ($this->isOneAdmin()) {
                $adminId = $admins[0]['id'];
                $adminExists = AdminUser::adminExistsById($adminId);
            } else if (!$this->isOneAdmin() && empty($adminId)) {
                while (!$adminExists) {
                    $adminId = $this->ask('Enter Admin Id');
                    $adminExists = AdminUser::adminExistsById($adminId);
                    if (!$adminExists) {
                        $this->error('Admin does not exist.');
                    }
                }
            }

            if ($adminExists) {
                $admin = AdminUser::where(['id' => $adminId, 'is_sys_admin' => true])->first();
                $this->changeRootAdmin($admin);
            } else {
                $this->error('Admin does not exist.');
            }
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * Get Admins that will be displayed.
     */
    public function isOneAdmin()
    {
        $adminsCount = AdminUser::whereIsSysAdmin(true)->count();
        if ($adminsCount === 1) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Get Admins that will be displayed.
     */
    public function getAdmins()
    {
        $admins = AdminUser::whereIsSysAdmin(true)->get(['id', 'email', 'name', 'first_name', 'last_name', 'is_active', 'is_root_admin'])->toArray();
        $admins = $this->mapAdmins($admins);
        return $admins;
    }

    /**
     * Get Admins that will be displayed.
     *
     * @param $admin
     */
    public function changeRootAdmin($admin)
    {
        $currentRootAdmin = AdminUser::whereIsRootAdmin(true)->first();
        $rootAdminExists = AdminUser::whereIsRootAdmin(true)->exists();
        if ($rootAdminExists && $currentRootAdmin->id === $admin->id && $currentRootAdmin->is_root_admin && $admin->is_root_admin) {
            $this->info('Admin \'' . $admin->toArray()['email'] . '\' is already root!');
        } else {
            if ($rootAdminExists) {
                AdminUser::unsetRoot($currentRootAdmin)->save();
            }
            AdminUser::setRoot($admin)->save();
            $this->info('\'' . $admin->email . '\' is now root admin!');
            $this->info('**********************************************************************************************************************');
        }
    }

    /**
     * Map admins to same view as on UI
     *
     * @param $admins
     * @return mixed
     */
    private function mapAdmins($admins)
    {
        $admins = $this->mapAdminConfirmed($admins);
        $admins = $this->mapAdminIsActive($admins);
        $admins = $this->mapAdminRoot($admins);

        return $admins;
    }

    /**
     * Map confirmed to respective string
     *
     * @param $admins
     * @return mixed
     */
    private function mapAdminConfirmed($admins)
    {
        foreach ($admins as $key => $admin) {
            $confirm_msg = 'N/A';

            if ($admin['confirmed']) {
                $confirm_msg = 'Confirmed';
            } elseif (!$admin['confirmed']) {
                $confirm_msg = 'Pending';
            }

            if ($admin['expired']) {
                $confirm_msg = 'Expired';
            }
            $admins[$key]['confirmed'] = $confirm_msg;
        }

        return $admins;
    }

    /**
     * Map is_active to true or false string
     *
     * @param $admins
     * @return mixed
     */
    private function mapAdminIsActive($admins)
    {
        foreach ($admins as $key => $admin) {
            if ($admin['is_active']) {
                $admins[$key]['is_active'] = 'true';
            } else {
                $admins[$key]['is_active'] = 'false';
            }
        }

        return $admins;
    }

    /**
     * Map is_root_admin to true or false string
     *
     * @param $admins
     * @return mixed
     */
    private function mapAdminRoot($admins)
    {
        foreach ($admins as $key => $admin) {
            if ($admin['is_root_admin']) {
                $admins[$key]['is_root_admin'] = 'true';
            } else {
                $admins[$key]['is_root_admin'] = 'false';
            }
        }

        return $admins;
    }
}
