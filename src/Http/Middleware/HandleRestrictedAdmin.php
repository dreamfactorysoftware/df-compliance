<?php

namespace DreamFactory\Core\Compliance\Http\Middleware;

use Closure;
use DreamFactory\Core\Compliance\Components\RestrictedAdmin;
use DreamFactory\Core\Utility\Environment;
use DreamFactory\Core\Enums\LicenseLevel;

class HandleRestrictedAdmin
{

    const RESTRICTED_ADMIN_METHODS = ['POST', 'PUT', 'PATCH'];

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
            return $next($request);
        };

        $route = $request->route();
        if ($this->isRestrictedAdminRequest($route, $request->getMethod())) {
            $this->handleRestrictedAdminRequest($request);
        };
        return $next($request);
    }

    /**
     * @param $route
     * @param $method
     * @return bool
     */
    private function isRestrictedAdminRequest($route, $method)
    {
        return in_array($method, self::RESTRICTED_ADMIN_METHODS) &&
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
        $method = $request->getMethod();
        $payload = $request->input();
        if ($isResourceWrapped) {
            foreach ($payload['resource'] as $key => $adminData) {
                $roleId = $this->handleAdminRole($adminData, $method);
                $payload['resource'][$key] = $this->getAdminData($adminData, $roleId);
            }
        } else {
            $roleId = $this->handleAdminRole($payload, $method);
            $payload = $this->getAdminData($payload, $roleId);
        }
        $request->replace($payload);
    }

    /**
     * @param $data
     * @param $requestMethod
     * @return integer
     * @throws \Exception
     */
    private function handleAdminRole($data, $requestMethod)
    {
        $isRestrictedAdmin = isset($data["is_restricted_admin"]) && $data["is_restricted_admin"];
        $accessByTabs = isset($data["access_by_tabs"]) ? $data["access_by_tabs"] : [];
        $restrictedAdminHelper = new RestrictedAdmin($data["email"], $accessByTabs);
        switch ($requestMethod) {
            case 'POST':
                {
                    if ($isRestrictedAdmin && !RestrictedAdmin::isAllTabs($accessByTabs)) {
                        $restrictedAdminHelper->createRestrictedAdminRole();
                    }
                    break;
                }
            case 'PUT':
            case 'PATCH':
                {
                    if ($isRestrictedAdmin && !RestrictedAdmin::isAllTabs($accessByTabs)) {
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
        $isRestrictedAdmin = isset($data["is_restricted_admin"]) && $data["is_restricted_admin"];
        $accessByTabs = isset($data["access_by_tabs"]) ? $data["access_by_tabs"] : [];
        if ($isRestrictedAdmin && !RestrictedAdmin::isAllTabs($accessByTabs)) {
            $restrictedAdminHelper = new RestrictedAdmin($data["email"], $accessByTabs, $roleId);
            // Links new role with admin via adding user_to_app_to_role_by_user_id array to request body
            $adminId = isset($data["id"]) ? $data["id"] : 0;
            $data["user_to_app_to_role_by_user_id"] = $restrictedAdminHelper->getUserAppRoleByUserId($isRestrictedAdmin, $adminId);
        }
        return $data;
    }
}