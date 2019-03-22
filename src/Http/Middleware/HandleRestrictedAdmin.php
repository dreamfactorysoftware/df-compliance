<?php

namespace DreamFactory\Core\Compliance\Http\Middleware;

use Closure;
use DreamFactory\Core\Compliance\Components\RestrictedAdmin;
use DreamFactory\Core\Utility\Environment;
use DreamFactory\Core\Enums\LicenseLevel;
use DreamFactory\Core\Exceptions\ForbiddenException;

class HandleRestrictedAdmin
{

    const RESTRICTED_ADMIN_METHODS = ['POST', 'PUT', 'PATCH'];

    private $method;
    private $payload;

    /**
     * @param         $request
     * @param Closure $next
     *
     * @return mixed
     * @throws \Exception
     */
    function handle($request, Closure $next)
    {
        // Ignore Restricted admin logic for non GOLD subscription
        if (Environment::getLicenseLevel() !== LicenseLevel::GOLD) {
            throw new ForbiddenException('Compliance is not available for your license. Please upgrade to Gold.');
        };

        $route = $request->route();
        $this->method = $request->getMethod();
        $this->payload = $request->input();
        if ($this->isRestrictedAdminRequest($route, $request->getMethod())) {
            $this->handleRestrictedAdminRequest($request);
        };
        return $next($request);
    }

    /**
     * @param $route
     * @return bool
     */
    private function isRestrictedAdminRequest($route)
    {
        return in_array($this->method, self::RESTRICTED_ADMIN_METHODS) &&
            $route->hasParameter('service') &&
            $route->parameter('service') === 'system' &&
            $route->hasParameter('resource') &&
            strpos($route->parameter('resource'), 'admin') !== false &&
            strpos($route->parameter('resource'), 'session') === false;
    }

    /**
     * @param $request
     * @return void
     * @throws \Exception
     */
    private function handleRestrictedAdminRequest($request)
    {
        $isResourceWrapped = isset($request->input()['resource']);
        if ($isResourceWrapped) {
            foreach ($this->payload['resource'] as $key => $adminData) {
                $roleId = $this->handleAdminRole($adminData, $this->method);
                $this->payload['resource'][$key] = $this->getAdminData($adminData, $roleId);
            }
        } else {
            $roleId = $this->handleAdminRole($this->payload, $this->method);
            $this->payload = $this->getAdminData($this->payload, $roleId);
        }
        $request->replace($this->payload);
    }

    /**
     * @param $data
     * @return integer
     * @throws \Exception
     */
    private function handleAdminRole($data)
    {
        $isRestrictedAdmin = $this->isRestrictedAdmin($data);
        $accessByTabs = $this->getAccessTabs($data);
        $restrictedAdminHelper = new RestrictedAdmin($data["email"], $accessByTabs);
        $isAllTabsSelected = RestrictedAdmin::isAllTabs($accessByTabs);
        switch ($this->method) {
            case 'POST':
                {
                    if ($isRestrictedAdmin && !$isAllTabsSelected) {
                        $restrictedAdminHelper->createRestrictedAdminRole();
                    }
                    break;
                }
            case 'PUT':
            case 'PATCH':
                {
                    if ($isRestrictedAdmin && !$isAllTabsSelected) {
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
     * @param $data
     * @param $roleId
     * @return array
     */
    private function getAdminData($data, $roleId)
    {
        $isRestrictedAdmin = $this->isRestrictedAdmin($data);
        $accessByTabs = $this->getAccessTabs($data);
        $isAllTabsSelected = RestrictedAdmin::isAllTabs($accessByTabs);
        if ($isRestrictedAdmin && !$isAllTabsSelected) {
            $restrictedAdminHelper = new RestrictedAdmin($data["email"], $accessByTabs, $roleId);
            // Links new role with admin via adding user_to_app_to_role_by_user_id array to request body
            $adminId = isset($data["id"]) ? $data["id"] : 0;
            $data["user_to_app_to_role_by_user_id"] = $restrictedAdminHelper->getUserAppRoleByUserId($isRestrictedAdmin, $adminId);
        }
        return $data;
    }

    /**
     * @param $data
     * @return bool
     */
    private function isRestrictedAdmin($data)
    {
        return isset($data["is_restricted_admin"]) && $data["is_restricted_admin"];
    }

    /**
     * @param $data
     * @return array
     */
    private function getAccessTabs($data)
    {
        return isset($data["access_by_tabs"]) ? $data["access_by_tabs"] : [];
    }
}