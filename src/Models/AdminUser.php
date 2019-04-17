<?php

namespace DreamFactory\Core\Compliance\Models;

use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Models\AdminUser as CoreAdminUser;

class AdminUser extends CoreAdminUser
{
    /**
     * Get Admin by email.
     *
     * @param $email
     * @return bool
     */
    public static function getAdminByEmail($email)
    {
        return self::whereEmail($email)->get()->toArray()[0];
    }

    /**
     * Set given admin as root.
     *
     * @param $admin
     * @return bool
     */
    public static function setRoot($admin)
    {
        if (!$admin->is_sys_admin) {
            throw new ForbiddenException('Only admins can be root.');
        } else {
            $admin->is_root_admin = true;
            return $admin;
        }
    }

    /**
     * Get is_root_admin of the admin with given id
     *
     * @param $id
     * @return bool|AdminUser
     */
    public static function isRootById($id)
    {
        return AdminUser::whereId($id)->first()->is_root_admin;
    }

}