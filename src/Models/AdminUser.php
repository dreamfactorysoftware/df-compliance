<?php

namespace DreamFactory\Core\Compliance\Models;

use DreamFactory\Core\Compliance\Utility\MiddlewareHelper;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Models\BaseSystemLookup;
use DreamFactory\Core\Models\Role;
use DreamFactory\Core\Models\RoleServiceAccess;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\Models\UserAppRole;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Core\Models\AdminUser as CoreAdminUser;
use Illuminate\Http\Request;
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
     * Fields the password and sign-in endpoints write.
     */
    const CREDENTIAL_FIELDS = ['last_login_date', 'confirm_code', 'password', 'remember_token'];

    /**
     * Endpoints that change or reset a password
     * or sign a user in, as [service, resource segments...].
     */
    const CREDENTIAL_ENDPOINTS = [
        ['user', 'password'],
        ['user', 'session'],
        ['system', 'admin', 'password'],
        ['system', 'admin', 'session'],
    ];

    /**
     * User methods that assign the roles configured on a sign-in or
     * registration service to the user signing in.
     */
    const SIGN_IN_ROLE_METHODS = ['applyAppRoleMapByService', 'applyDefaultUserAppRole'];

    /**
     * df-scheduler task model. Referenced by name so the package works
     * without df-scheduler installed.
     */
    const SCHEDULER_TASK_MODEL = 'DreamFactory\\Core\\Scheduler\\Models\\SchedulerTask';

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
     *   - password and sign-in fields, when the request is to a password or
     *     sign-in endpoint (password reset, sign-in).
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
            if (!array_diff($fields, static::CREDENTIAL_FIELDS) && static::isCredentialRequest()) {
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
     * A scheduled task runs with the session of its user_id. Only a root
     * admin may save a task that runs as an admin other than themselves.
     * Tasks that run as the caller, as an ordinary user, or with no user
     * are not affected.
     *
     * @param \Illuminate\Database\Eloquent\Model $task
     *
     * @throws ForbiddenException
     */
    public static function authorizeRunAs($task)
    {
        $userId = $task->getAttribute('user_id');
        if (empty($userId) || !static::doesSysAdminRootExist()) {
            return;
        }

        $callerId = Session::getCurrentUserId();
        if ($callerId && ($callerId == $userId || static::isRootById($callerId))) {
            return;
        }
        if (!static::adminExistsById($userId)) {
            return;
        }

        throw new ForbiddenException('Only root admin can schedule tasks that run as another admin.');
    }

    /**
     * Restricted admin roles (roles assigned to an admin), their service
     * access and lookups, and an admin's role assignments and lookups are
     * changed only by a root admin, like the admin accounts themselves.
     * Before a root admin exists, they are not checked. Roles that the
     * sign-in and registration services assign to the user signing in are
     * applied as configured on the service.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @throws ForbiddenException
     */
    public static function authorizeRelatedChange($model)
    {
        $userIds = static::relatedValues($model, $model instanceof UserAppRole || $model instanceof BaseSystemLookup ? 'user_id' : null);
        $roleIds = static::relatedValues(
            $model,
            $model instanceof Role ? $model->getKeyName() :
                ($model instanceof RoleServiceAccess || $model instanceof BaseSystemLookup ? 'role_id' : null)
        );

        $forAdmin = !empty($userIds) && User::whereIn('id', $userIds)->where('is_sys_admin', true)->exists();
        $forAdminRole = !$forAdmin && !empty($roleIds) && static::adminRoles()->whereIn('role_id', $roleIds)->exists();
        if (!$forAdmin && !$forAdminRole) {
            return;
        }
        if (!static::doesSysAdminRootExist()) {
            return;
        }

        $callerId = Session::getCurrentUserId();
        if ($callerId && static::isRootById($callerId)) {
            return;
        }
        if ($model instanceof UserAppRole && static::isSignInRoleAssignment()) {
            return;
        }

        throw new ForbiddenException($forAdmin ? 'Only root admin can manage admins.' :
            'You do not have permission to modify restricted admin roles. Please contact your root administrator.');
    }

    /**
     * Whether authorizeRelatedChange() applies to the model.
     *
     * @param mixed $model
     *
     * @return bool
     */
    public static function isAdminRelatedModel($model)
    {
        return $model instanceof Role || $model instanceof RoleServiceAccess ||
            $model instanceof UserAppRole || $model instanceof BaseSystemLookup;
    }

    /**
     * Whether the current request is to a password or sign-in endpoint.
     * Artisan commands, including scheduled tasks, never are.
     *
     * @return bool
     */
    public static function isCredentialRequest()
    {
        if (null !== static::consoleCommand() || !app()->bound('request')) {
            return false;
        }

        $request = app('request');
        if (!$request instanceof Request) {
            return false;
        }

        $target = MiddlewareHelper::getRoutedTarget($request);
        foreach (static::CREDENTIAL_ENDPOINTS as $endpoint) {
            $service = array_shift($endpoint);
            if (MiddlewareHelper::targetMatches($target, $service, $endpoint, true) &&
                count($target['resource']) === count($endpoint)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the role assignment being saved comes from a sign-in or
     * registration service applying its configured roles to the user
     * signing in.
     *
     * @return bool
     */
    protected static function isSignInRoleAssignment()
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (isset($frame['class']) && is_a($frame['class'], User::class, true) &&
                in_array($frame['function'] ?? null, static::SIGN_IN_ROLE_METHODS, true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Current and stored values of a model attribute, without empty values.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param string|null                         $attribute
     *
     * @return array
     */
    protected static function relatedValues($model, $attribute)
    {
        if (null === $attribute) {
            return [];
        }

        return array_values(array_unique(array_filter(
            [$model->getAttribute($attribute), $model->getOriginal($attribute)],
            function ($value) {
                return null !== $value && '' !== $value;
            }
        )));
    }

    /**
     * Role assignments that belong to admins.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function adminRoles()
    {
        return UserAppRole::whereIn('user_id', User::where('is_sys_admin', true)->select('id'));
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