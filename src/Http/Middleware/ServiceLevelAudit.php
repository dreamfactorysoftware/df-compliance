<?php

namespace DreamFactory\Core\Compliance\Http\Middleware;

use DreamFactory\Core\Compliance\Models\AdminUser;
use DreamFactory\Core\Compliance\Models\ServiceReport;
use DreamFactory\Core\Compliance\Utility\LicenseCheck;
use DreamFactory\Core\Compliance\Utility\MiddlewareHelper;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Enums\Verbs;

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
        $this->method = $request->getMethod();
        $this->payload = $request->input();
        $this->request = $request;

        $this->validateServiceReportRequest();

        if ($this->isServiceRequest()) {
            $this->resource = $this->request->route()->parameter('resource');
            $serviceReportsData = $this->getReportsData();
            $response = $next($request);

            $this->createServiceReports($response, $serviceReportsData);
        } else {
            return $next($request);
        }

        return $response;
    }

    /**
     * Does user has permission to use system/service_report endpoint
     *
     * @return void
     */
    private function validateServiceReportRequest()
    {
        if(MiddlewareHelper::requestUrlContains($this->request, 'system/service_report')){
            if (!AdminUser::isCurrentUserRootAdmin()) {
                throw new ForbiddenException('Service Reports only available for root admin.');
            }

            if (!LicenseCheck::isGoldLicense()) {
                throw new ForbiddenException('Service reports are not available for your license. Please upgrade to Gold.');
            }
        };
    }

    /**
     * Is request goes to system/service, except GET requests
     *
     * @return bool
     */
    protected function isServiceRequest()
    {
        return $this->method !== Verbs::GET &&
               MiddlewareHelper::requestUrlContains($this->request, 'system/service');
    }

    /**
     * Get service name by id or from request payload
     *
     * @param $service
     * @return bool
     */
    protected function getServiceName($service)
    {
        $serviceName = '';
        if (gettype($service) === "string") {
            $serviceName = $this->getServiceNameById($service);
        } elseif (isset($service['id'])) {
            $serviceName = $this->getServiceNameById($service['id']);
        } elseif (isset($service['name'])) {
            $serviceName = $service['name'];
        }

        return $serviceName;
    }

    /**
     * Get service name by given id
     *
     * @param $serviceId
     * @return bool
     */
    protected function getServiceNameById($serviceId)
    {
        $serviceName = '';
        if (!is_null($serviceId) && ('' !== $serviceId)) {
            $serviceName = ServiceManager::getServiceNameById($serviceId);
        }

        return $serviceName;
    }

    /**
     * Get service id from request, the one that is in URL
     *
     * @return bool
     */
    protected function getServiceIdFromResource()
    {
        return array_get((!empty($this->resource)) ? explode('/', $this->resource) : [], 1);
    }

    /**
     * Get action caption for the report
     *
     * @return bool
     */
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

    /**
     * Get currently logged in user email
     *
     * @return array
     */
    protected function getUserEmail()
    {
        $user = Session::user();
        $userEmail = $user->email;
        return $userEmail;
    }

    /**
     * Get service reports data array
     *
     * @return array
     */
    protected function getReportsData()
    {
        $reportsData = [];
        if ($this->getServiceIdFromResource()) {
            $reportsData[] = $this->getReportData($this->getServiceIdFromResource());
        } elseif ($this->hasIdsParameter()) {
            foreach ($this->getIdsFromPayload() as $serviceId) {
                $reportsData[] = $this->getReportData($serviceId);
            }
        } elseif ($this->isResourceWrapped()) {
            foreach ($this->payload['resource'] as $serviceData) {
                $reportsData[] = $this->getReportData($serviceData);
            };
        }
        return $reportsData;
    }

    /**
     * get a service report data
     *
     * @param $serviceData
     * @return mixed
     */
    protected function getReportData($serviceData)
    {
        return ['service_id' => $this->getServiceId($serviceData),
            'service_name' => $this->getServiceName($serviceData),
            'user_email' => $this->getUserEmail(),
            'action' => $this->getAction(),
            'request_verb' => $this->method];
    }

    /**
     * Create service reports except failed ones
     *
     * @param $response
     * @param $reportsData
     * @return void
     */
    protected function createServiceReports($response, $reportsData)
    {
        if (!$this->isFailure($response)) {
            foreach ($reportsData as $report) {
                ServiceReport::create($report)->save();
            }
        }
    }

    /**
     * Is payload wrapped in resource array
     *
     * @return bool
     */
    protected function isResourceWrapped()
    {
        return isset($this->payload['resource']);
    }

    /**
     * Is request failed
     *
     * @param $response
     * @return bool
     */
    protected function isFailure($response)
    {
        return isset($response->getOriginalContent()['error']) || $response->status() >= 400;
    }

    /**
     * Has ?ids parameter specified
     *
     * @return bool
     */
    protected function hasIdsParameter()
    {
        return isset($this->payload['ids']);
    }

    /**
     * Get ?ids= from the request
     *
     * @return array
     */
    protected function getIdsFromPayload()
    {
        return explode(',', $this->payload['ids']);
    }

    /**
     * Get service_id from URL or from payload
     *
     * @param $serviceData
     * @return array
     */
    protected function getServiceId($serviceData)
    {
        if (gettype($serviceData) === "string") {
            return $serviceData;
        } else {
            return isset($serviceData['id']) ? $serviceData['id'] : null;
        }
    }
}
