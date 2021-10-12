<?php

namespace DreamFactory\Core\Compliance\Http\Middleware;

use DreamFactory\Core\Compliance\Components\RestrictedAdmin;
use DreamFactory\Core\Compliance\Models\AdminUser;
use DreamFactory\Core\Compliance\Utility\LicenseCheck;
use DreamFactory\Core\Compliance\Utility\MiddlewareHelper;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Enums\Verbs;
use Illuminate\Support\Str;

use Closure;

class HandleRestrictedAdmin
{
    // Request methods restricted admin logic use
    const RESTRICTED_ADMIN_METHODS = [Verbs::POST, Verbs::PUT, Verbs::PATCH];

    private $method;
    private $reqPayload;
    private $resContent;
    private $request;
    private $response;
    private $isRestrictedAdmin;

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
        $this->reqPayload = $request->input();
        $this->response = $next($request);
        $this->resContent = $this->response;
        $this->isRestrictedAdmin = $this->isRestrictedAdmin();

        if (!$this->isAdminRequest()) {
            return $this->response;
        }

        if (!AdminUser::isCurrentUserRootAdmin()) {
            throw new ForbiddenException('Only root admin can manage admins.');
        }

        if ($this->isRestrictedAdmin && $this->response->isSuccessful() && in_array($this->method, self::RESTRICTED_ADMIN_METHODS)) {
            if (!LicenseCheck::isGoldLicense()) {
                throw new ForbiddenException('Restricted admins are not available for your license. Please upgrade to Gold.');
            };

            $this->resContent = $this->response->getOriginalContent();
            $this->handleRestrictedAdmin();
        };


        return $this->response;
    }

    /**
     * Does request go to system/admin/* endpoint (except system/admin/session)
     *
     * @return bool
     */
    private function isAdminRequest()
    {
        return MiddlewareHelper::requestUrlContains($this->request, 'system/admin') &&
            !MiddlewareHelper::requestUrlContains($this->request, 'system/admin/profile') &&
            !MiddlewareHelper::requestUrlContains($this->request, 'session') &&
            !MiddlewareHelper::requestUrlContains($this->request, 'password');
    }

    /**
     * Replace request payload with restricted admin role linked to the admin
     *
     * @return void
     * @throws \Exception
     */
    private function handleRestrictedAdmin()
    {
        foreach ($this->getAdminsData() as $adminData) {
            $this->handleAdminRole($adminData);
        }
    }

    /**
     * Create, update or delete restricted admin role
     *
     * @param $adminData
     * @return void
     * @throws \Exception
     */
    private function handleAdminRole($adminData)
    {
        $accessByTabs = $this->getAccessTabs($adminData);
        $restrictedAdminHelper = new RestrictedAdmin($adminData["email"], $accessByTabs);
        $isRestrictedAdmin = isset($adminData['is_restricted_admin']) ? $adminData['is_restricted_admin'] : false;
        $notAllTabsSelected = !RestrictedAdmin::isAllTabs($accessByTabs);

        switch ($this->method) {
            case 'POST':
                {
                    if ($isRestrictedAdmin && $notAllTabsSelected) {
                        $restrictedAdminHelper->createRestrictedAdminRole();
                        $restrictedAdminHelper->handleUserAppRole($isRestrictedAdmin, $adminData['id']);
                    }
                    break;
                }
            case 'PUT':
            case 'PATCH':
                {
                    if ($isRestrictedAdmin && $notAllTabsSelected) {
                        $restrictedAdminHelper->updateRestrictedAdminRole();
                        $restrictedAdminHelper->handleUserAppRole($isRestrictedAdmin, $adminData['id']);
                    } else {
                        $restrictedAdminHelper->deleteRole();
                    };
                    break;
                }
        }
    }

    /**
     * @return bool
     */
    private function isRestrictedAdmin()
    {
        if (isset($this->reqPayload['resource'])) {
            foreach ($this->reqPayload['resource'] as $key => $adminData) {
                if (isset($adminData["is_restricted_admin"]) && $adminData['is_restricted_admin']) {
                    return true;
                }
            }
            return false;
        } else {
            return isset($this->reqPayload["is_restricted_admin"]) && $this->reqPayload["is_restricted_admin"];
        }
    }

    /**
     * Get data for RA (from request and response)
     *
     * @return array
     */
    private function getAdminsData()
    {
        $adminsData = [];

        $isResourceWrapped = isset($this->resContent['resource']);
        if ($isResourceWrapped) {
            $adminsData = $this->addParams($this->resContent['resource']);
        } else if (!isset($this->resContent['id'])) {
            $adminsData = $this->addParams($this->resContent);
        } else {
            $adminId = $this->resContent['id'];
            $adminsData[] = $this->addRestrictedAdminParameters(AdminUser::whereId($adminId)->first()->toArray());
        }

        return $adminsData;
    }

    function addParams($data)
    {
        $result = [];
        foreach ($data as $key => $adminData) {
            $adminId = $adminData['id'];
            $result[] = $this->addRestrictedAdminParameters(AdminUser::whereId($adminId)->first()->toArray());
        }

        return $result;
    }

    /**
     * Get tabs that were selected in the widget
     *
     * @param $adminData
     * @return array
     */
    private function addRestrictedAdminParameters($adminData)
    {
        $adminEmail = isset($adminData['email']) ? $adminData['email'] : '';

        $isResourceWrapped = isset($this->reqPayload['resource']);
        if ($isResourceWrapped) {
            foreach ($this->reqPayload['resource'] as $adminRequestData) {
                if ($adminEmail === $adminRequestData['email']) {
                    $adminData['access_by_tabs'] = $this->getAccessTabs($adminRequestData);
                    $adminData['is_restricted_admin'] = $this->getIsRestrictedAdmin($adminRequestData);
                }
            }
        } else {
            $adminData['access_by_tabs'] = $this->getAccessTabs($this->reqPayload);
            $adminData['is_restricted_admin'] = $this->getIsRestrictedAdmin($this->reqPayload);
        }

        return $adminData;
    }

    /**
     * Get accessible tabs from admin request data
     *
     * @param $adminData
     * @return array
     */
    private function getAccessTabs($adminData)
    {
        return isset($adminData['access_by_tabs']) ? $adminData['access_by_tabs'] : [];
    }

    /**
     * Get is_restricted_admin tabs from admin request data
     *
     * @param $adminData
     * @return array
     */
    private function getIsRestrictedAdmin($adminData)
    {
        return isset($adminData['is_restricted_admin']) ? $adminData['is_restricted_admin'] : [];
    }
}