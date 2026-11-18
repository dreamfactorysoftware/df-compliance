<?php


namespace DreamFactory\Core\Compliance\Http\Middleware;

use Closure;
use DreamFactory\Core\Compliance\Models\AdminUser;
use DreamFactory\Core\Compliance\Utility\MiddlewareHelper;

class MarkAsRootAdmin
{
    private $method;
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
        $this->method = $request->getMethod();
        $response = $next($request);

        if (!$this->isSessionRequest() || !method_exists($response, 'getOriginalContent')) {
            return $response;
        }

        if (is_array($content = $response->getOriginalContent()) && !isset($content['error'])) {
            $content['is_root_admin'] = isset($content['id']) && AdminUser::isRootById($content['id']);
            $response->setContent($content);
        }
        return $response;
    }

    /**
     * Does request go to system/admin/session or user/session
     *
     * @return bool
     */
    private function isSessionRequest()
    {
        return MiddlewareHelper::isRoutedTo($this->request, 'system', 'admin', 'session') ||
            MiddlewareHelper::isRoutedTo($this->request, 'user', 'session');
    }
}