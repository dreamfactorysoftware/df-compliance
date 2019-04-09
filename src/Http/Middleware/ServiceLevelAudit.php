<?php

namespace DreamFactory\Core\Compliance\Http\Middleware;

use DreamFactory\Core\Compliance\Models\ServiceReport;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Core\Utility\ResourcesWrapper;

use Closure;
use Route;
use ServiceManager;

class ServiceLevelAudit
{
    protected $route;
    protected $method;
    protected $resource;
    protected $payload;
    protected $request;

    /**
     * @param Request $request
     * @param Closure $next
     *
     * @return array|mixed|string
     */
    public function handle($request, Closure $next)
    {
        $this->route = $request->route();
        $this->method = $request->getMethod();
        $this->resource = $this->route->parameter('resource');
        $this->payload = $request->input();
        $this->request = $request;

        if ($this->isServiceRequest()) {
            $this->createServiceReport();
        }

        return $next($request);
    }

    protected function isServiceRequest()
    {
        return $this->route->hasParameter('service') &&
            $this->route->parameter('service') === 'system' &&
            $this->route->hasParameter('resource') &&
            strpos($this->route->parameter('resource'), 'service') !== false &&
            $this->method !== 'GET';
    }

    protected function getServiceName($service)
    {
        $serviceId = array_get((!empty($this->resource)) ? explode('/', $this->resource) : [], 1);
        if (!is_null($serviceId) && ('' !== $serviceId)) {
            return ServiceManager::getServiceNameById($serviceId);
        } elseif(isset($service['name'])) {
            return $service['name'];
        }
        return '';
    }

    protected function getAction()
    {
        $action = '';
        switch ($this->method) {
            case 'POST':
                {
                    $action = 'Service created';
                    break;
                }
            case 'PUT':
            case 'PATCH':
                {
                    $action = 'Service modified';
                    break;
                }
            case 'DELETE':
                {
                    $action = 'Service deleted';
                    break;
                }
        }
        return $action;
    }

    protected function getUserEmail()
    {
        $user = Session::user();
        $userEmail = '';
        if ($user) $user = $user->toArray();
        if (isset($user['email'])) $userEmail = $user['email'];
        return $userEmail;
    }

    /**
     * @param $serviceData
     * @return mixed
     */
    protected function getReportData($serviceData)
    {
        return ['service_name' => $this->getServiceName($serviceData),
            'user_email' => $this->getUserEmail(),
            'action' => $this->getAction(),
            'request_verb' => $this->method];
    }

    protected function createServiceReport()
    {
        if ($this->isResourceWrapped()) {
            foreach ($this->payload['resource'] as $service) {
                ServiceReport::create($this->getReportData($service))->save();
            }
        } else {
            ServiceReport::create($this->getReportData($this->payload))->save();
        }
    }

    protected function isResourceWrapped()
    {
        return isset($this->payload['resource']);
    }


}
