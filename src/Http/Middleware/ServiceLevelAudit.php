<?php

namespace DreamFactory\Core\Compliance\Http\Middleware;

use DreamFactory\Core\Compliance\Models\AdminUser;
use DreamFactory\Core\Compliance\Models\ServiceReport;
use DreamFactory\Core\Compliance\Utility\LicenseCheck;
use DreamFactory\Core\Compliance\Utility\MiddlewareHelper;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\System\Resources\Service as ServiceResource;

use ServiceManager;
use Closure;

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
        $this->request = $request;

        if ($refusal = $this->validateServiceReportRequest()) {
            return $refusal;
        }

        if (!$this->isServiceRequest()) {
            return $next($request);
        }

        try {
            $this->payload = MiddlewareHelper::getPayload($request);
            $this->resource = $this->getServiceResourceSegments();
            $serviceReportsData = $this->getReportsData();
        } catch (\Throwable $e) {
            return MiddlewareHelper::errorResponse($e);
        }

        $response = $next($request);
        $this->createServiceReports($response, $serviceReportsData);

        return $response;
    }

    /**
     * Does user has permission to use system/service_report endpoint
     *
     * @return \Symfony\Component\HttpFoundation\Response|null the 403 response, or null when allowed
     */
    private function validateServiceReportRequest()
    {
        if (MiddlewareHelper::addresses($this->request, 'system', 'service_report')) {
            if (!AdminUser::isCurrentUserRootAdmin()) {
                return MiddlewareHelper::forbiddenResponse('Service Reports only available for root admin.');
            }

            if (!LicenseCheck::isGoldLicense()) {
                return MiddlewareHelper::forbiddenResponse('Service reports are not available for your license. Please upgrade to Gold.');
            }
        }

        return null;
    }

    /**
     * Is the request dispatched to system/service, except GET requests
     *
     * @return bool
     */
    protected function isServiceRequest()
    {
        return $this->method !== Verbs::GET &&
               MiddlewareHelper::isRoutedTo($this->request, 'system', 'service');
    }

    /**
     * Resource segments (e.g. ['service', '12']) of the routed target
     *
     * @return array
     */
    protected function getServiceResourceSegments()
    {
        $target = MiddlewareHelper::getRoutedTarget($this->request);

        return MiddlewareHelper::targetMatches($target, 'system', ['service'], true) ? $target['resource'] : [];
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
        if (is_scalar($service)) {
            $serviceName = $this->getServiceNameById((string)$service);
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
            if (empty($serviceName)) {
                // The ServiceManager id->name map is cached forever and can go stale
                // (e.g. service rows created outside a purge). Fall back to the DB.
                $serviceName = \DreamFactory\Core\Models\Service::whereId($serviceId)->value('name') ?? '';
            }
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
        $id = $this->resource[1] ?? null;

        return ('' === $id) ? null : $id;
    }

    /**
     * Get action caption for the report
     *
     * @return bool
     */
    protected function getAction()
    {
        $action = '';
        $action = match ($this->method) {
            'POST' => 'Service created',
            'PUT', 'PATCH' => 'Service modified',
            'DELETE' => 'Service deleted',
            default => $action,
        };
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
        if ($user) {
            return $user->email;
        }
        
        // For API key authentication, return a meaningful identifier
        $apiKey = Session::getApiKey();
        if ($apiKey) {
            // Try to get app name from the API key
            try {
                $appId = \DreamFactory\Core\Models\App::getAppIdByApiKey($apiKey);
                if ($appId) {
                    $app = \DreamFactory\Core\Models\App::find($appId);
                    if ($app) {
                        return 'api:' . $app->name;
                    }
                }
            } catch (\Exception $e) {
                // Fall back to using partial API key
            }
            // If we can't get app name, use first 8 chars of API key as identifier
            return 'api:' . substr($apiKey, 0, 8) . '...';
        }
        
        // Default fallback
        return 'system';
    }

    /**
     * Get service reports data array
     *
     * @return array
     */
    protected function getReportsData()
    {
        $reportsData = [];
        foreach ($this->getAffectedServices() as $serviceData) {
            if (is_array($serviceData) || is_scalar($serviceData)) {
                $reportsData[] = $this->getReportData($serviceData);
            }
        }

        return $reportsData;
    }

    /**
     * Services the request works on, as ids or records, chosen in the same
     * order as the system/service resource chooses them
     *
     * @return array
     */
    protected function getAffectedServices()
    {
        if ($id = $this->getServiceIdFromResource()) {
            return [$id];
        }

        if ($this->method === Verbs::POST) {
            return $this->getPayloadRecords();
        }

        if ($this->hasIdsParameter()) {
            return $this->getIdsFromPayload();
        }

        if ($this->method === Verbs::DELETE && !empty($records = $this->getPayloadRecords())) {
            return $records;
        }

        if (!empty($this->request->query('filter'))) {
            return MiddlewareHelper::getIdsFromFilter(ServiceResource::class) ?? [];
        }

        return $this->getPayloadRecords();
    }

    /**
     * Records in the request body (a list, or the body as one record)
     *
     * @return array
     */
    protected function getPayloadRecords()
    {
        return MiddlewareHelper::getRecordList($this->payload) ?? [];
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
                try {
                    ServiceReport::create($report);
                } catch (\Throwable $e) {
                    // Audit bookkeeping must never fail the request that already succeeded.
                    \Log::warning('Service audit write failed: ' . $e->getMessage(), ['report' => $report]);
                }
            }
        }
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
        return !empty($this->getIdsFromPayload());
    }

    /**
     * Get ?ids= from the request (comma separated or a list)
     *
     * @return array
     */
    protected function getIdsFromPayload()
    {
        return MiddlewareHelper::getIdList($this->request->query('ids'));
    }

    /**
     * Get service_id from URL or from payload
     *
     * @param $serviceData
     * @return array
     */
    protected function getServiceId($serviceData)
    {
        if (is_scalar($serviceData)) {
            return (string)$serviceData;
        } else {
            return $serviceData['id'] ?? null;
        }
    }
}
