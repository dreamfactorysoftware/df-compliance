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
     * Set given user as root.
     *
     * @param $user
     * @return bool
     */
    public static function setRoot($user)
    {
        if (!$user->is_sys_admin) {
            throw new ForbiddenException('Only admins can be root.');
        } else {
            return $user->is_root_admin = 1;
        }
    }
}