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
                $this->payload['resource'][$key] = $this->getPayloadDataWithHandledRole($adminData);
            }
        } else {
            $this->payload = $this->getPayloadDataWithHandledRole($this->payload);
        }
        $this->request->replace($this->payload);
    }

    /**
     * @param $requestData
     * @return integer
     * @throws \Exception
     */
    private function handleAdminRole($requestData)
    {
        $isRestrictedAdmin = $this->isRestrictedAdmin($requestData);
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
     * @param $requestData
     * @param $roleId
     * @return array
     */
    private function getAdminData($requestData, $roleId)
    {
        $isRestrictedAdmin = $this->isRestrictedAdmin($requestData);
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
     * @param $requestData
     * @return bool
     */
    private function isRestrictedAdmin($requestData)
    {
        return isset($requestData["is_restricted_admin"]) && $requestData["is_restricted_admin"];
    }

    /**
     * @param $requestData
     * @return array
     */
    private function getAccessTabs($requestData)
    {
        return isset($requestData["access_by_tabs"]) ? $requestData["access_by_tabs"] : [];
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
}