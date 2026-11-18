<?php

namespace DreamFactory\Core\Compliance\Models;

use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Core\Models\AdminUser as CoreAdminUser;
use Symfony\Component\Console\Input\ArgvInput;

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
     * Console commands an operator runs by hand. Model changes made while one
     * of these runs are not checked. Every other console command (scheduled
     * tasks, queue workers) is checked like a web request.
     */
    const OPERATOR_COMMANDS = [
        'df:setup',
        'df:root_admin',
        'df:import',
        'df:import-pkg',
        'df:request',
        'tinker',
        'migrate',
        'db:seed',
    ];

    /**
     * Fields that sign-in and account confirmation write for any user.
     */
    const SIGN_IN_FIELDS = ['last_login_date', 'confirm_code'];

    /**
     * Fields that flows without a session user (sign-in, password reset) write.
     */
    const NO_SESSION_FIELDS = ['last_login_date', 'confirm_code', 'password', 'remember_token'];

    /**
     * Name of the artisan command this process runs. False until resolved,
     * null when the process is not an artisan command.
     *
     * @var string|null|false
     */
    protected static $consoleCommand = false;

    /**
     * Only a root admin may create, change or delete an admin. This runs on
     * the model, so it holds for every route that reaches the user table.
     *
     * Allowed without root:
     *   - the first admin on a new install, before a root admin exists;
     *   - an admin editing their own record;
     *   - sign-in and account confirmation fields, for any caller;
     *   - password and sign-in fields when there is no session user
     *     (sign-in, password reset).
     * None of these may grant or remove admin rights.
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
        if (!static::doesSysAdminRootExist()) {
            return;
        }

        $callerId = Session::getCurrentUserId();
        if ($callerId && static::isRootById($callerId)) {
            return;
        }

        $grants = $deleting || !$user->exists || $user->isDirty('is_sys_admin') || $user->isDirty('is_root_admin');
        if (!$grants) {
            $fields = array_keys($user->getDirty());
            if ($callerId && $callerId == $user->id) {
                return;
            }
            if (!array_diff($fields, static::SIGN_IN_FIELDS)) {
                return;
            }
            if (!$callerId && !array_diff($fields, static::NO_SESSION_FIELDS)) {
                return;
            }
        }

        if (!$deleting && $user->exists && $user->wasRecentlyCreated && !$user->getOriginal('is_sys_admin')) {
            // The record was inserted moments ago as the first step of this
            // admin create. Remove it so the refused create leaves nothing.
            static::removeRecord($user);
        }

        throw new ForbiddenException('Only root admin can manage admins.');
    }

    /**
     * Whether model changes skip authorizeChange(). Web requests never do.
     * In the console, operator commands and processes that are not artisan
     * commands (such as the test runner) do; every other command does not.
     *
     * @return bool
     */
    public static function isOperatorConsole()
    {
        if (!app()->runningInConsole()) {
            return false;
        }

        $command = static::consoleCommand();

        return null === $command || in_array($command, static::OPERATOR_COMMANDS, true);
    }

    /**
     * The artisan command this process runs, or null outside artisan.
     *
     * @return string|null
     */
    public static function consoleCommand()
    {
        if (false === static::$consoleCommand) {
            $argv = $_SERVER['argv'] ?? [];
            static::$consoleCommand = null;
            if (is_array($argv) && 'artisan' === basename((string)($argv[0] ?? ''))) {
                static::$consoleCommand = (string)(new ArgvInput(array_values($argv)))->getFirstArgument();
            }
        }

        return static::$consoleCommand;
    }

    /**
     * Set the artisan command name; false resolves it again from argv.
     *
     * @param string|null|false $command
     */
    public static function setConsoleCommand($command)
    {
        static::$consoleCommand = $command;
    }

    /**
     * Whether a root admin with admin rights exists. A user marked root that
     * is not an admin yet (the first admin partway through its creation)
     * does not count.
     *
     * @return bool
     */
    protected static function doesSysAdminRootExist()
    {
        return AdminUser::whereIsRootAdmin(true)->whereIsSysAdmin(true)->exists();
    }

    /**
     * @param \DreamFactory\Core\Models\User $user
     */
    protected static function removeRecord($user)
    {
        try {
            $user->newQueryWithoutScopes()->whereKey($user->getKey())->delete();
            $user->exists = false;
        } catch (\Throwable $ex) {
            \Log::warning('Could not remove user ' . $user->getKey() . ': ' . $ex->getMessage());
        }
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