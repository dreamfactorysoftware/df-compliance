<?php

namespace DreamFactory\Core\Compliance\Http\Middleware;

use Closure;
use DreamFactory\Core\Compliance\Models\AdminUser;
use DreamFactory\Core\Compliance\Utility\MiddlewareHelper;
use DreamFactory\Core\Models\UserAppRole;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\System\Resources\Role as RoleResource;

class HandleRestrictedAdminRole
{
    // Request methods that change roles
    const MODIFY_METHODS = [Verbs::POST, Verbs::PUT, Verbs::PATCH, Verbs::DELETE];

    protected $request;
    protected $method;

    /**
     * @param         $request
     * @param Closure $next
     *
     * @return mixed
     * @throws \Exception
     */
    function handle($request, Closure $next)
    {
        $this->request = $request;
        $this->method = $request->getMethod();

        if (!in_array($this->method, self::MODIFY_METHODS) ||
            !MiddlewareHelper::addresses($request, 'system', 'role')
        ) {
            return $next($request);
        }

        $roleIds = $this->getRestrictedAdminRoleIds();
        if (!empty($roleIds)) {
            if (!AdminUser::isCurrentUserRootAdmin()) {
                return MiddlewareHelper::forbiddenResponse('You do not have permission to modify restricted admin roles. Please contact your root administrator.');
            }

            foreach ($roleIds as $roleId) {
                \Cache::forget('role:' . $roleId);
            }
        }

        return $next($request);
    }

    /**
     * Ids of the restricted admin roles the request changes
     *
     * @return array
     */
    protected function getRestrictedAdminRoleIds()
    {
        $roleIds = static::getRequestedRoleIds($this->request, MiddlewareHelper::getPayload($this->request));
        $filter = $this->request->input('filter');

        if (!empty($filter)) {
            $filterIds = MiddlewareHelper::getIdsFromFilter(RoleResource::class);
            if (null === $filterIds) {
                return $this->getAllRestrictedAdminRoleIds();
            }
            $roleIds = array_merge($roleIds, $filterIds);
        } elseif (empty($roleIds) && $this->method === Verbs::DELETE &&
            MiddlewareHelper::isTrue($this->request->input('force'))
        ) {
            // Deleting with force and nothing selected removes every role.
            return $this->getAllRestrictedAdminRoleIds();
        }

        if (empty($roleIds)) {
            return [];
        }

        return $this->restrictedAdminRoles()
            ->whereIn('role_id', array_values(array_unique($roleIds)))
            ->pluck('role_id')->unique()->values()->all();
    }

    /**
     * Ids of all restricted admin roles
     *
     * @return array
     */
    protected function getAllRestrictedAdminRoleIds()
    {
        return $this->restrictedAdminRoles()->pluck('role_id')->unique()->values()->all();
    }

    /**
     * Ids of the roles a request names: in the URL, in the ids option and in
     * the records of the request body. Ids selected by a filter are not included.
     *
     * @param \Illuminate\Http\Request $request
     * @param array                    $payload the request body
     *
     * @return string[]
     */
    public static function getRequestedRoleIds($request, array $payload)
    {
        $roleIds = [];

        foreach (MiddlewareHelper::getRequestTargets($request) as $target) {
            $id = $target['resource'][1] ?? null;
            if (MiddlewareHelper::targetMatches($target, 'system', ['role']) && null !== $id && '' !== $id) {
                $roleIds[] = $id;
            }
        }

        $roleIds = array_merge(
            $roleIds,
            MiddlewareHelper::getIdList($request->input('ids')),
            MiddlewareHelper::getIdList($payload['ids'] ?? null),
            MiddlewareHelper::getRecordIds($payload)
        );

        return array_values(array_unique(array_map('strval', $roleIds)));
    }

    /**
     * Role assignments that belong to admins
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function restrictedAdminRoles()
    {
        return UserAppRole::whereIn('user_id', AdminUser::where('is_sys_admin', true)->select('id'));
    }
}
