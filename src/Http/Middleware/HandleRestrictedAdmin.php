<?php

namespace DreamFactory\Core\Compliance\Http\Middleware;

use DreamFactory\Core\Compliance\Components\RestrictedAdmin;
use DreamFactory\Core\Compliance\Models\AdminUser;
use DreamFactory\Core\Compliance\Utility\LicenseCheck;
use DreamFactory\Core\Compliance\Utility\MiddlewareHelper;
use DreamFactory\Core\Enums\Verbs;

use Closure;

class HandleRestrictedAdmin
{
    // Request methods restricted admin logic use
    const RESTRICTED_ADMIN_METHODS = [Verbs::POST, Verbs::PUT, Verbs::PATCH];

    // system/admin sub-resources every admin may use for their own account
    const OWN_ACCOUNT_RESOURCES = ['profile', 'session', 'password'];

    private $method;
    private $reqPayload = [];
    private $reqRecords = null;
    private $resContent;
    private $request;
    private $response;
    private $isRestrictedAdmin = false;

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

        // Authorize BEFORE running the downstream handler. A rejected caller
        // must never reach the handler, or the write commits before the 403.
        if (!static::isAdminManagementRequest($request)) {
            return $next($request);
        }

        if (!AdminUser::isCurrentUserRootAdmin()) {
            return MiddlewareHelper::forbiddenResponse('Only root admin can manage admins.');
        }

        $this->reqPayload = MiddlewareHelper::getPayload($request);
        $this->reqRecords = MiddlewareHelper::getRecordList($this->reqPayload);
        $this->isRestrictedAdmin = $this->isRestrictedAdmin();

        if ($this->isRestrictedAdmin && in_array($this->method, self::RESTRICTED_ADMIN_METHODS)) {
            if (!LicenseCheck::isGoldLicense()) {
                return MiddlewareHelper::forbiddenResponse('Restricted admins are not available for your license. Please upgrade to Gold.');
            }
        }

        // Authorization passed. Now run the handler.
        $this->response = $next($request);
        $this->resContent = $this->response;

        // Post-process restricted-admin role creation. This needs the
        // response, so it stays after $next.
        if ($this->isRestrictedAdmin && in_array($this->method, self::RESTRICTED_ADMIN_METHODS) &&
            static::isRoutedToAdmins($request) && $this->response->isSuccessful()
        ) {
            try {
                $this->resContent = $this->response->getOriginalContent();
                $this->handleRestrictedAdmin();
            } catch (\Throwable $e) {
                return MiddlewareHelper::errorResponse($e);
            }
        }

        return $this->response;
    }

    /**
     * Does the request go to the system/admin endpoint, other than the caller's
     * own system/admin/profile, system/admin/session and system/admin/password,
     * or use the admin option of system/user.
     *
     * Targets from both the matched route parameters and the canonical request
     * path are considered.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    public static function isAdminManagementRequest($request)
    {
        foreach (MiddlewareHelper::getRequestTargets($request) as $target) {
            if (static::isAdminsTarget($target)) {
                return true;
            }
            if (MiddlewareHelper::targetMatches($target, 'system', ['user']) && static::hasAdminOption($request)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is the request dispatched to the system/admin resource itself (not to
     * the caller's own profile, session or password)
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    private static function isRoutedToAdmins($request)
    {
        $target = MiddlewareHelper::getRoutedTarget($request);

        return MiddlewareHelper::targetMatches($target, 'system', ['admin'], true) && static::isAdminsTarget($target);
    }

    /**
     * Does a target name the system/admin resource. The profile, session and
     * password sub-resources are matched by exact name.
     *
     * @param array $target
     *
     * @return bool
     */
    private static function isAdminsTarget($target)
    {
        return MiddlewareHelper::targetMatches($target, 'system', ['admin']) &&
            !in_array($target['resource'][1] ?? null, self::OWN_ACCOUNT_RESOURCES, true);
    }

    /**
     * Is the admin option set (it makes system/user work with admin accounts)
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    private static function hasAdminOption($request)
    {
        return MiddlewareHelper::isTrue($request->input('admin')) ||
            MiddlewareHelper::isTrue(MiddlewareHelper::getPayload($request)['admin'] ?? null);
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
        $isRestrictedAdmin = $adminData['is_restricted_admin'] ?? false;
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
                    }
                    break;
                }
        }
    }

    /**
     * Does the request payload ask for a restricted admin
     *
     * @return bool
     */
    private function isRestrictedAdmin()
    {
        foreach ($this->reqRecords ?? [$this->reqPayload] as $adminData) {
            if (is_array($adminData) && !empty($adminData['is_restricted_admin'])) {
                return true;
            }
        }

        return false;
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
        $adminEmail = $adminData['email'] ?? '';

        if (null !== $this->reqRecords) {
            foreach ($this->reqRecords as $adminRequestData) {
                if (is_array($adminRequestData) && $adminEmail === ($adminRequestData['email'] ?? null)) {
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
        return $adminData['access_by_tabs'] ?? [];
    }

    /**
     * Get is_restricted_admin tabs from admin request data
     *
     * @param $adminData
     * @return array
     */
    private function getIsRestrictedAdmin($adminData)
    {
        return $adminData['is_restricted_admin'] ?? [];
    }
}