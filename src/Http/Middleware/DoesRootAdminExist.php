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

        if (!$this->isEnvironmentRequest()) {
            return $response;
        }

        $content = $response->getOriginalContent();
        $content['platform']['root_admin_exists'] = AdminUser::doesRootAdminExist();
        $response->setContent($content);

        return $response;
    }

    /**
     * Does request go to admin/session
     *
     * @return bool
     */
    private function isEnvironmentRequest()
    {
        return MiddlewareHelper::requestUrlContains($this->request, 'environment');
    }
}