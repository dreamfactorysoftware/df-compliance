<?php

namespace DreamFactory\Core\Compliance\Http\Middleware;

use Closure;
use DreamFactory\Core\Compliance\Components\RestrictedAdmin;
use DreamFactory\Core\Utility\Environment;
use DreamFactory\Core\Enums\LicenseLevel;

class AccessibleTabs
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
        // Ignore Restricted admin logic for non GOLD subscription
        if(Environment::getLicenseLevel() !== LicenseLevel::GOLD) {
            return $next($request);
        };

        $response = $next($request);
        $route = $request->route();
        $method = $request->getMethod();
        if ($this->isGetRoleRequest($route, $method) && $this->isAccessibleTabsSpecified($request->only('accessible_tabs'))) {
            $content = $this->getContentWithAccessibleTabs($response->getOriginalContent());
            $response->setContent($content);
        };
        return $response;
    }

    /**
     * @param $route
     * @param $method
     * @return bool
     */
    private function isGetRoleRequest($route, $method)
    {
        return $method === "GET" &&
            $route->hasParameter('service') &&
            $route->parameter('service') === 'system' &&
            $route->hasParameter('resource') &&
            strpos($route->parameter('resource'), 'role') !== false;
    }

    /**
     * @param $rolesInfo
     * @return bool
     */
    private function getContentWithAccessibleTabs($rolesInfo)
    {
        if (isset($rolesInfo['resource'])) {
            foreach ($rolesInfo['resource'] as $key => $item) {
                $rolesInfo['resource'][$key] = $this->addAccessibleTabs($item);
            }
        } else {
            $rolesInfo = $this->addAccessibleTabs($rolesInfo);
        }
        return $rolesInfo;
    }

    /**
     * @param $options
     * @return bool
     */
    private static function isAccessibleTabsSpecified($options)
    {
        return isset($options["accessible_tabs"]) && $options["accessible_tabs"] && to_bool($options["accessible_tabs"]);
    }

    /**
     * @param $roleInfo
     * @return bool
     */
    private static function addAccessibleTabs($roleInfo)
    {
        $roleInfo['accessible_tabs'] = RestrictedAdmin::getAccessibleTabsByRoleId($roleInfo["id"]);
        return $roleInfo;
    }
}