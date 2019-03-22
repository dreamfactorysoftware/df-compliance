<?php
namespace DreamFactory\Core\Compliance\Models;
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
}