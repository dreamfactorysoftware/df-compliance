<?php

namespace DreamFactory\Core\Compliance\Commands;

use DreamFactory\Core\Compliance\Models\AdminUser;
use DreamFactory\Core\Exceptions\NotFoundException;
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
            $adminId = null;

            $admins = $this->getAllAdmins();
            $this->printAdmins($admins);

            if ($this->option('admin_id')) {
                $adminId = $this->option('admin_id');
            } elseif ($this->isSingleAdmin()) {
                $adminId = $admins[0]['id'];
            } else {
                while (!AdminUser::adminExistsById($adminId)) {
                    $adminId = $this->ask('Enter Admin Id');
                    if (!AdminUser::adminExistsById($adminId)) {
                        $this->error('Admin does not exist.');
                    }
                }
            }

            if (!AdminUser::adminExistsById($adminId)) {
                throw new NotFoundException("Admin does not exist");
            }

            $admin = AdminUser::where(['id' => $adminId, 'is_sys_admin' => true])->first();
            $this->changeRootAdmin($admin);

        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * Is there only one admin.
     */
    public function isSingleAdmin()
    {
        return AdminUser::whereIsSysAdmin(true)->count() == 1;
    }

    /**
     * Get Admins that will be displayed.
     */
    public function getAllAdmins()
    {
        $admins = AdminUser::whereIsSysAdmin(true)->get(['id', 'email', 'name', 'first_name', 'last_name', 'is_active', 'is_root_admin'])->toArray();
        return $admins;
    }

    /**
     * Make another admin root.
     *
     * @param $admin
     */
    public function changeRootAdmin($admin)
    {
        $currentRootAdmin = AdminUser::whereIsRootAdmin(true)->first();
        $rootAdminExists = AdminUser::whereIsRootAdmin(true)->exists();

        if ($rootAdminExists) {
            AdminUser::unsetRoot($currentRootAdmin)->save();
        }

        AdminUser::makeRoot($admin)->save();
        $this->info('\'' . $admin->email . '\' is now root admin!');
        $this->info('**********************************************************************************************************************');
    }

    /**
     * Print admins table.
     *
     * @param $admins
     */
    protected function printAdmins($admins)
    {
        $admins = $this->humanizeAdminRecords($admins);

        $headers = ['Id', 'Email', 'Display Name', 'First Name', 'Last Name', 'Active', 'Root Admin', 'Registration'];

        $this->info('**********************************************************************************************************************');
        $this->info('Admins');
        $this->table($headers, $admins);
        $this->info('**********************************************************************************************************************');
    }

    /**
     * Map admins to the same view as on UI
     *
     * @param $admins
     * @return mixed
     */
    private function humanizeAdminRecords($admins)
    {
        $admins = $this->humanizeAdminConfirmationStatus($admins);

        foreach ($admins as $key => $admin) {
            $admins[$key]['is_active'] = var_export($admin['is_active'], true);
            $admins[$key]['is_root_admin'] = var_export(to_bool($admin['is_root_admin']), true);
        }

        return $admins;
    }

    /**
     * Map confirmed to respective string.
     *
     * @param $admins
     * @return mixed
     */
    private function humanizeAdminConfirmationStatus($admins)
    {
        foreach ($admins as $key => $admin) {

            switch ($admin) {
                case ($admin['confirmed']):
                    {
                        $confirm_msg = 'Confirmed';
                        break;
                    }
                case (!$admin['confirmed']):
                    {
                        $confirm_msg = 'Pending';
                        break;
                    }
                case ($admin['expired']):
                    {
                        $confirm_msg = 'Expired';
                        break;
                    }
                default:{
                    $confirm_msg = 'N/A';
                }
            }

            $admins[$key]['confirmed'] = $confirm_msg;
        }

        return $admins;
    }
}
