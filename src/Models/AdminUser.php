<?php

namespace DreamFactory\Core\Compliance\Models;

use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Utility\Session;
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
     * Does Admin with given id exists.
     *
     * @param $id
     * @return bool
     */
    public static function adminExistsById($id)
    {
        return self::where(['id' => $id, 'is_sys_admin' => true])->exists();
    }

    /**
     * Does logged in user is root admin.
     *
     * @return bool
     */
    public static function isCurrentUserRootAdmin()
    {
        $userId = Session::getCurrentUserId();
        if (empty($userId)) {
            return false;
        }

        return (bool)(self::whereId($userId)->first()?->is_root_admin);
    }

    /**
     * Only a root admin may create, change or delete an admin. This runs on
     * the model, so it holds for every route that reaches the user table.
     *
     * Allowed without root: the first admin (no root exists yet), an admin
     * editing their own record, and flows with no session user (login
     * timestamp, password reset). None of these may grant admin rights.
     *
     * @param \DreamFactory\Core\Models\User $user
     * @param bool                             $deleting
     *
     * @throws ForbiddenException
     */
    public static function authorizeChange($user, $deleting = false)
    {
        $isAdmin = $user->is_sys_admin || $user->getOriginal('is_sys_admin');
        if (!$isAdmin && !$user->isDirty('is_root_admin')) {
            return;
        }
        if (!static::doesRootAdminExist()) {
            return;
        }

        $callerId = Session::getCurrentUserId();
        if ($callerId && static::isRootById($callerId)) {
            return;
        }

        $grants = $deleting || !$user->exists || $user->isDirty('is_sys_admin') || $user->isDirty('is_root_admin');
        if (!$grants && (!$callerId || $callerId == $user->id)) {
            return;
        }

        throw new ForbiddenException('Only root admin can manage admins.');
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
     * Does root admin exist.
     *
     * @return bool
     */
    public static function doesRootAdminExist()
    {
        return AdminUser::whereIsRootAdmin(true)->exists();
    }

    /**
     * Get is_root_admin of the admin with given id
     *
     * @param $id
     * @return bool|AdminUser
     */
    public static function isRootById($id)
    {
        return AdminUser::whereId($id)->first()?->is_root_admin ?? false;
    }
}