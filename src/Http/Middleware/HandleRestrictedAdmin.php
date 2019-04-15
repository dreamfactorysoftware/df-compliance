<?php

namespace DreamFactory\Core\Compliance\Http\Middleware;

use DreamFactory\Core\Compliance\Components\RestrictedAdmin;
use DreamFactory\Core\Compliance\Models\AdminUser;
use DreamFactory\Core\Compliance\Utility\LicenseCheck;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Models\UserAppRole;
use DreamFactory\Core\Enums\Verbs;

use Closure;

class HandleRestrictedAdmin
{

    // Request methods restricted admin logic use
    const RESTRICTED_ADMIN_METHODS = [Verbs::POST, Verbs::PUT, Verbs::PATCH];

    private $method;
    private $payload;
    private $request;
    private $route;

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
        $this->method = $request->getMethod();
        $this->payload = $request->input();

        if ($this->isDeleteRestrictedAdminRoleRequest() && !AdminUser::isCurrentUserRootAdmin()) {
            throw new ForbiddenException('You do not have permission to delete restricted admin\'s role. Please contact your root administrator.');
        }

        if ($this->isRestrictedAdminRequest() && AdminUser::isCurrentUserRootAdmin()) {
            if (!LicenseCheck::isGoldLicense()) {
                throw new ForbiddenException('Restricted admins are not available for your license. Please upgrade to Gold.');
            };

            $this->handleRestrictedAdminRequest();
        };

        return $next($request);
    }

    /**
     * Does request go to system/admin/* endpoint (except system/admin/session)
     *
     * @return bool
     */
    private function isRestrictedAdminRequest()
    {
        return in_array($this->method, self::RESTRICTED_ADMIN_METHODS) &&
            $this->route->hasParameter('service') &&
            $this->route->parameter('service') === 'system' &&
            $this->route->hasParameter('resource') &&
            strpos($this->route->parameter('resource'), 'admin') !== false &&
            strpos($this->route->parameter('resource'), 'session') === false &&
            $this->isRestrictedAdmin();
    }

    /**
     * Is request goes to system/role/* endpoint
     *
     * @return bool
     */
    private function isDeleteRestrictedAdminRoleRequest()
    {
        $roleIds = $this->getResourceId();

        if (count($roleIds) === 0) {
            $roleIds = $this->request->input('ids') ? explode(',', $this->request->input('ids')) : [];
        }

        return $this->method === Verbs::DELETE &&
            $this->route->hasParameter('service') &&
            $this->route->parameter('service') === 'system' &&
            $this->route->hasParameter('resource') &&
            strpos($this->route->parameter('resource'), 'role') !== false &&
            $this->isRestrictedAdminRoles($roleIds);
    }

    /**
     * Is any role belong to any RA
     *
     * @param $roleIds
     * @return boolean
     */
    protected function isRestrictedAdminRoles($roleIds)
    {
        foreach ($roleIds as $roleId) {
            if (UserAppRole::whereRoleId($roleId)->exists() && AdminUser::adminExistsById($this->getUserIdFromUserAppRole($roleId))) {
                return true;
            }
        };
        return false;
    }

    /**
     * Replace request payload with restricted admin role linked to the admin
     *
     * @return void
     * @throws \Exception
     */
    private function handleRestrictedAdminRequest()
    {
        $isResourceWrapped = isset($this->payload['resource']);
        if ($isResourceWrapped) {
            foreach ($this->payload['resource'] as $key => $adminData) {
                $this->payload['resource'][$key] = $this->getPayloadDataWithHandledRole($adminData);
            }
        } else {
            $this->payload = $this->getPayloadDataWithHandledRole($this->payload);
        }
        $this->request->replace($this->payload);
    }

    /**
     * @param $requestData
     * @return array
     * @throws \Exception
     */
    private function getPayloadDataWithHandledRole($requestData)
    {
        $roleId = $this->handleAdminRole($requestData);
        return $this->getAdminData($requestData, $roleId);
    }


    /**
     * Create, update or delete restricted admin role
     *
     * @param $requestData
     * @return integer
     * @throws \Exception
     */
    private function handleAdminRole($requestData)
    {
        $isRestrictedAdmin = $this->isRestrictedAdmin();
        $accessByTabs = $this->getAccessTabs($requestData);
        $restrictedAdminHelper = new RestrictedAdmin($requestData["email"], $accessByTabs);
        $notAllTabsSelected = !RestrictedAdmin::isAllTabs($accessByTabs);

        switch ($this->method) {
            case 'POST':
                {
                    if ($isRestrictedAdmin && $notAllTabsSelected) {
                        $restrictedAdminHelper->createRestrictedAdminRole();
                    }
                    break;
                }
            case 'PUT':
            case 'PATCH':
                {
                    if ($isRestrictedAdmin && $notAllTabsSelected) {
                        $restrictedAdminHelper->updateRestrictedAdminRole();
                    } else {
                        $restrictedAdminHelper->deleteRole();
                    };
                    break;
                }
        }
        return $restrictedAdminHelper->getRole()['id'];
    }

    /**
     * Add user_to_app_to_role_by_user_id array to the admin data from request to link Role with the Admin
     *
     * @param $requestData
     * @param $roleId
     * @return array
     */
    private function getAdminData($requestData, $roleId)
    {
        $isRestrictedAdmin = $this->isRestrictedAdmin();
        $accessByTabs = $this->getAccessTabs($requestData);
        $notAllTabsSelected = !RestrictedAdmin::isAllTabs($accessByTabs);

        if ($isRestrictedAdmin && $notAllTabsSelected) {
            $restrictedAdminHelper = new RestrictedAdmin($requestData["email"], $accessByTabs, $roleId);

            // Links new role with admin via adding user_to_app_to_role_by_user_id array to request body
            $adminId = isset($requestData["id"]) ? $requestData["id"] : 0;
            $requestData["user_to_app_to_role_by_user_id"] = $restrictedAdminHelper->getUserAppRoleByUserId($isRestrictedAdmin, $adminId);
        }
        return $requestData;
    }

    /**
     * @return bool
     */
    private function isRestrictedAdmin()
    {
        if (isset($this->payload['resource'])) {
            foreach ($this->payload['resource'] as $key => $adminData) {
                if (isset($adminData["is_restricted_admin"]) && $adminData['is_restricted_admin']) {
                    return true;
                }
            }
            return false;
        } else {
            return isset($this->payload["is_restricted_admin"]) && $this->payload["is_restricted_admin"];
        }
    }

    /**
     * Get tabs that were selected in the widget
     *
     * @param $requestData
     * @return array
     */
    private function getAccessTabs($requestData)
    {
        return isset($requestData["access_by_tabs"]) ? $requestData["access_by_tabs"] : [];
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
}