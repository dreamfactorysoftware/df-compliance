<?php


namespace DreamFactory\Core\Compliance\Http\Middleware;

use Closure;
use DreamFactory\Core\Compliance\Models\AdminUser;
use DreamFactory\Core\Enums\Verbs;

class RootAdmin
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

        if ($this->isSessionRequest()) {
            $content = $response->getOriginalContent();
            $content['is_root_admin'] = AdminUser::isAdminRootById($content['id']) ? true : false;
            $response->setContent($content);
            return $response;
        } else {
            return $response;
        }
    }

    /**
     * Should set Admin As Root
     *
     * @return bool
     */
    private function isSessionRequest()
    {
        return $this->method === Verbs::POST &&
            $this->route->hasParameter('service') &&
            $this->route->parameter('service') === 'system' &&
            $this->route->hasParameter('resource') &&
            $this->route->parameter('resource') === 'admin/session';
    }
}