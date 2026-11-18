<?php


namespace DreamFactory\Core\Compliance\Http\Middleware;

use Closure;
use DreamFactory\Core\Compliance\Models\AdminUser;
use DreamFactory\Core\Compliance\Utility\MiddlewareHelper;

class DoesRootAdminExist
{
    private $request;

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
        $response = $next($request);

        if (!$this->isEnvironmentRequest() || !method_exists($response, 'getOriginalContent')) {
            return $response;
        }

        $content = $response->getOriginalContent();
        if (!is_array($content) || isset($content['error'])) {
            return $response;
        }

        if (!isset($content['platform']) || !is_array($content['platform'])) {
            $content['platform'] = [];
        }
        $content['platform']['root_admin_exists'] = AdminUser::doesRootAdminExist();
        $response->setContent($content);

        return $response;
    }

    /**
     * Does request go to system/environment
     *
     * @return bool
     */
    private function isEnvironmentRequest()
    {
        return MiddlewareHelper::isRoutedTo($this->request, 'system', 'environment');
    }
}