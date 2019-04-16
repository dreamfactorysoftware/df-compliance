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
//                            todo: {--first : make the first admin root}'; by created_at column

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
            $admins = $this->mapAdmins($this->getAdmins());

            $this->printAdmins($admins);

            $adminId = $this->option('admin_id');
            $adminExists = AdminUser::adminExistsById($adminId);

            if ($this->isSingleAdmin()) {
                $adminId = $admins[0]['id'];
                $adminExists = AdminUser::adminExistsById($adminId);
            } else if (!$this->isSingleAdmin() && empty($adminId)) {
                while (!$adminExists) {
                    $adminId = $this->ask('Enter Admin Id');
                    $adminExists = AdminUser::adminExistsById($adminId);
                    if (!$adminExists) {
                        $this->error('Admin does not exist.');
                    }
                }
            }

            if (!$adminExists) {
                throw new NotFoundException("Admin does not exist");
            }

            $admin = AdminUser::where(['id' => $adminId, 'is_sys_admin' => true])->first();
            $this->changeRootAdmin($admin);

        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * Get Admins that will be displayed.
     */
    public function isSingleAdmin()
    {
        return AdminUser::whereIsSysAdmin(true)->count() == 1;
    }

    /**
     * Get Admins that will be displayed.
     */
    public function getAdmins()
    {
        $admins = AdminUser::whereIsSysAdmin(true)->get(['id', 'email', 'name', 'first_name', 'last_name', 'is_active'])->toArray();
        return $admins;
    }

    /**
     * Get Admins that will be displayed.
     *
     * @param $admin
     */
    public function changeRootAdmin($admin)
    {
//        $currentRootAdmin = AdminUser::whereIsRootAdmin(true)->first();
//        $rootAdminExists = AdminUser::whereIsRootAdmin(true)->exists();
        /*if ($rootAdminExists && $currentRootAdmin->id === $admin->id && $currentRootAdmin->is_root_admin && $admin->is_root_admin) {
            $this->info('Admin \'' . $admin->toArray()['email'] . '\' is already root!');
        } else {*/
//        if ($rootAdminExists) {
//            AdminUser::unsetRoot($currentRootAdmin)->save();
//        }
//        AdminUser::setRoot($admin)->save();
        $this->info('\'' . $admin->email . '\' is now root admin!');
        $this->info('**********************************************************************************************************************');
//        }
    }

    /**
     * @param $admins
     */
    protected function printAdmins($admins): void
    {
        $headers = ['Id', 'Email', 'Display Name', 'First Name', 'Last Name', 'Active', 'Root Admin', 'Registration'];

        $this->info('**********************************************************************************************************************');
        $this->info('Admins');
        $this->table($headers, $admins);
        $this->info('**********************************************************************************************************************');
    }

    /**
     * Map admins to same view as on UI
     *
     * @param $admins
     * @return mixed
     */
    private function mapAdmins($admins)
    {
        $admins = $this->humanizeAdminConfirmationStatus($admins);
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
    private function humanizeAdminConfirmationStatus($admins)
    {
        foreach ($admins as $key => $admin) {
            $confirm_msg = 'N/A';

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
                $admins[$key]['is_active'] = to_bool($admin['is_active']);
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
            $admins[$key]['is_root_admin'] = to_bool($admin['is_root_admin']);
        }

        return $admins;
    }
}
