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
        // Ignore Restricted admin logic for non GOLD subscription
        if (Environment::getLicenseLevel() !== LicenseLevel::GOLD) {
            throw new ForbiddenException('Compliance is not available for your license. Please upgrade to Gold.');
        };

        $this->request = $request;
        $this->route = $request->route();
        $this->method = $request->getMethod();
        $this->payload = $request->input();

        if ($this->isRestrictedAdminRequest()) {
            $this->handleRestrictedAdminRequest();
        };
        return $next($request);
    }

    /**
     * @return bool
     */
    private function isRestrictedAdminRequest()
    {
        return in_array($this->method, self::RESTRICTED_ADMIN_METHODS) &&
            $this->route->hasParameter('service') &&
            $this->route->parameter('service') === 'system' &&
            $this->route->hasParameter('resource') &&
            strpos($this->route->parameter('resource'), 'admin') !== false &&
            strpos($this->route->parameter('resource'), 'session') === false;
    }

    /**
     * @return void
     * @throws \Exception
     */
    private function handleRestrictedAdminRequest()
    {
        $isResourceWrapped = isset($this->payload['resource']);
        if ($isResourceWrapped) {
            foreach ($this->payload['resource'] as $key => $adminData) {
                $roleId = $this->handleAdminRole($adminData);
                $this->payload['resource'][$key] = $this->getAdminData($adminData, $roleId);
            }
        } else {
            $roleId = $this->handleAdminRole($this->payload);
            $this->payload = $this->getAdminData($this->payload, $roleId);
        }
        $this->request->replace($this->payload);
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
     * @param $data
     * @param $roleId
     * @return array
     */
    private function getAdminData($data, $roleId)
    {
        $isRestrictedAdmin = $this->isRestrictedAdmin($data);
        $accessByTabs = $this->getAccessTabs($data);
        $notAllTabsSelected = !RestrictedAdmin::isAllTabs($accessByTabs);
        if ($isRestrictedAdmin && $notAllTabsSelected) {
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