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

        if (!$this->isSessionRequest()) {
            return $response;
        }

        if(is_array($content = $response->getOriginalContent()) && method_exists($response,'getOriginalContent')) {
            $content['is_root_admin'] = isset($content['id']) && AdminUser::isRootById($content['id']);
            $response->setContent($content);
        }
        return $response;
    }

    /**
     * Does request go to admin/session
     *
     * @return bool
     */
    private function isSessionRequest()
    {
        return MiddlewareHelper::requestUrlContains($this->request, 'session');
    }
}