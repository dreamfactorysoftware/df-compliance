<?php


namespace DreamFactory\Core\Compliance\Http\Middleware;

use Closure;
use DreamFactory\Core\Compliance\Models\AdminUser;
use DreamFactory\Core\Enums\Verbs;

class MarkAsRootAdmin
{
    private $method;
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
        $this->route = $request->route();
        $this->method = $request->getMethod();
        $response = $next($request);

        if (!$this->isAdminSessionRequest()) {
            return $response;
        }

        $content = $response->getOriginalContent();
        $content['is_root_admin'] = isset($content['id']) && AdminUser::isRootById($content['id']);
        $response->setContent($content);

        return $response;
    }

    /**
     * Does request go to admin/session
     *
     * @return bool
     */
    private function isAdminSessionRequest()
    {
        return $this->route->hasParameter('resource') &&
            strpos($this->route->parameter('resource'), 'session') !== false;
    }
}