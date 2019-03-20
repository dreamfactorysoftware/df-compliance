<?php

namespace DreamFactory\Core\Compliance\Http\Middleware;

use Closure;
use DreamFactory\Core\Compliance\Components\RestrictedAdmin;

class HandleRestrictedAdmin
{

    /**
     * @param         $request
     * @param Closure $next
     *
     * @return mixed
     * @throws \Exception
     */
    function handle($request, Closure $next)
    {
        $route = $request->route();
        $reqMethod = $request->getMethod();
        if ($this->isAdminRequest($route)) {
            switch ($reqMethod) {
                case 'POST':
                    {
                        $this->handleRestrictedAdmin($request, 'create');
                        break;
                    }
                case 'PUT':
                case 'PATCH':
                    {
                        $this->handleRestrictedAdmin($request, 'update');
                        break;
                    }
            }
        };
        return $next($request);
    }

    /**
     * @param $route
     * @return bool
     */
    private function isAdminRequest($route): bool
    {
        return $route->hasParameter('service') &&
            $route->parameter('service') === 'system' &&
            $route->hasParameter('resource') &&
            strpos($route->parameter('resource'), 'admin') !== false;
    }

    /**
     * @param $request
     * @param $action
     * @throws \Exception
     */
    private function handleRestrictedAdmin($request, $action): void
    {
        if ($request->has('resource')) {
            $this->handleRequestWithResource($request, $action);
        } else {
            $this->handleRequestWithoutResource($request, $action);
        }
    }

    /**
     * @param $request
     * @param $action
     * @throws \Exception
     */
    private function handleRequestWithResource($request, $action): void
    {
        $reqPayload = $request->input();
        foreach ($reqPayload['resource'] as $key => $item) {
            $reqPayload['resource'][$key] = $this->getUpdatedAdminData($action, $item);
        }
        $request->replace($reqPayload);
    }

    /**
     * @param $request
     * @param $action
     * @throws \Exception
     */
    private function handleRequestWithoutResource($request, $action): void
    {
        $request->replace($this->getUpdatedAdminData($action, $request->input()));
    }

    /**
     * @param $action
     * @param $adminData
     * @return array
     * @throws \Exception
     */
    private function getUpdatedAdminData($action, $adminData): array
    {
        //TODO: think about a better name, as this function delete update and create restricted admin role
        $isRestrictedAdmin = isset($adminData["is_restricted_admin"]) && $adminData["is_restricted_admin"];
        $accessByTabs = isset($adminData["access_by_tabs"]) ? $adminData["access_by_tabs"] : [];
        $restrictedAdminHelper = new RestrictedAdmin($adminData["email"], $accessByTabs);
        if ($isRestrictedAdmin && !RestrictedAdmin::isAllTabs($accessByTabs)) {
            switch ($action) {
                case 'create':
                    {
                        $restrictedAdminHelper->createRestrictedAdminRole();
                        // Links new role with admin via adding user_to_app_to_role_by_user_id array to request body
                        $adminData["user_to_app_to_role_by_user_id"] = $restrictedAdminHelper->getUserAppRoleByUserId($isRestrictedAdmin);
                        break;
                    }
                case 'update':
                    {
                        $restrictedAdminHelper->updateRestrictedAdminRole();
                        // Links new role with admin via adding user_to_app_to_role_by_user_id array to request body
                        $adminId = isset($adminData["id"]) ? $adminData["id"] : null;
                        $adminData["user_to_app_to_role_by_user_id"] = $restrictedAdminHelper->getUserAppRoleByUserId($isRestrictedAdmin, $adminId);
                        break;
                    }
            };
        } else {
            $restrictedAdminHelper->deleteRole();
        };
        return $adminData;
    }
}