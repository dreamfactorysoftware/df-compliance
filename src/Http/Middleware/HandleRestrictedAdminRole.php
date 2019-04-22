<?php

namespace DreamFactory\Core\Compliance\Http\Middleware;

use Closure;
use DreamFactory\Core\Compliance\Models\AdminUser;
use DreamFactory\Core\Models\UserAppRole;
use DreamFactory\Core\Exceptions\ForbiddenException;

class HandleRestrictedAdminRole
{

    protected $request;
    protected $route;

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
        $this->route = $request->route();

        if ($this->isDeleteRestrictedAdminRoleRequest() && !AdminUser::isCurrentUserRootAdmin()) {
            throw new ForbiddenException('You do not have permission to delete restricted admin\'s role. Please contact your root administrator.');
        }

        return $next($this->request);
    }

    /**
     * Does request go to system/role/* endpoint
     *
     * @return bool
     */
    private function isDeleteRestrictedAdminRoleRequest()
    {
        $roleIds = $this->getResourceId();

        if (count($roleIds) === 0) {
            $roleIds = $this->getIdsParameter();
        }

        return $this->request->getMethod() === Verbs::DELETE &&
            strpos($this->request->url(), 'system/role') !== false &&
            $this->isRestrictedAdminRolesByIds($roleIds);
    }

    /**
     * Does any role belong to any RA
     *
     * @param $roleIds
     * @return boolean
     */
    protected function isRestrictedAdminRolesByIds($roleIds)
    {
        foreach ($roleIds as $roleId) {
            if ($this->isRestrictedAdminRole($roleId)) {
                return true;
            }
        };
        return false;
    }

    /**
     * Get resource Id
     *
     * @return array
     */
    private function getResourceId()
    {
        $id = array_get((!empty($this->route->parameter('resource'))) ? explode('/', $this->route->parameter('resource')) : [], 1);
        return $id ?
            [$id] :
            [];
    }

    /**
     * @param $roleId
     * @return mixed
     */
    protected function getUserIdFromUserAppRole($roleId)
    {
        return UserAppRole::whereRoleId($roleId)->get()->toArray()[0]['user_id'];
    }

    /**
     * Does role with this id belong to a restricted admin
     *
     * @param $roleId
     * @return bool
     */
    protected function isRestrictedAdminRole($roleId)
    {
        return UserAppRole::whereRoleId($roleId)->exists() && AdminUser::adminExistsById($this->getUserIdFromUserAppRole($roleId));
    }

    /**
     * Get ?ids= parameter
     *
     * @return array
     */
    private function getIdsParameter()
    {
        return $this->request->input('ids') ? explode(',', $this->request->input('ids')) : [];
    }

}