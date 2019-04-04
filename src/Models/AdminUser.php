<?php

namespace DreamFactory\Core\Compliance\Models;

use DreamFactory\Core\Models\AdminUser as CoreAdminUser;
use DreamFactory\Core\Exceptions\ForbiddenException;

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
     * Does Admin with given id exists.
     *
     * @param $admin
     * @param $id
     * @return bool
     */
    public static function adminExistsById($id)
    {
        return self::where(['id' => $id, 'is_sys_admin' => true])->exists();
    }

    /**
     * Set given admin as root.
     *
     * @param $admin
     * @return bool
     */
    public static function makeRoot($admin)
    {
        $admin->is_root_admin = true;
        return $admin;
    }

    /**
     * Unset given admin as root.
     *
     * @param $admin
     * @return bool
     */
    public static function unsetRoot($admin)
    {
        $admin->is_root_admin = false;
        return $admin;
    }

    /**
     * Get is_root_admin of the admin with given id
     *
     * @param $id
     * @return bool|AdminUser
     */
    public static function isAdminRootById($id)
    {
        return AdminUser::whereId($id)->first()->is_root_admin;
    }

}