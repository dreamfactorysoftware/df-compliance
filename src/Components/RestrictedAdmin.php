<?php

namespace DreamFactory\Core\Compliance\Components;

use DreamFactory\Core\Compliance\Enums\ServiceRequestorTypes;
use DreamFactory\Core\Compliance\Enums\VerbsMask;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Models\App;
use DreamFactory\Core\Models\Role;
use DreamFactory\Core\Models\RoleServiceAccess;
use DreamFactory\Core\Models\UserAppRole;
use DreamFactory\Core\Utility\Session;
use Illuminate\Support\Arr;
use ServiceManager;

/**
 * Services access by tabs for admin role
 */
class RestrictedAdmin
{

    /**
     * Accessible tabs.
     *
     * @type array
     */
    protected $tabs = [];

    /**
     * User email.
     *
     * @type string
     */
    protected $email = "";

    /**
     * Role id to create service access.
     *
     * @type int
     */
    protected $roleId = 0;

    /**
     * Default system service name
     *
     * @type int
     */
    const SYSTEM_SERVICE_NAME = "system";

    /**
     * @param string $email
     * @param array $tabs
     * @param int $roleId
     */
    public function __construct(string $email, array $tabs = [], int $roleId = 0)
    {
        $this->email = $email;
        $this->tabs = $tabs;
        $this->roleId = $roleId;
    }

    /**
     * Create role and its service accesses.
     *
     * @throws \Exception
     */
    public function createRestrictedAdminRole()
    {
        $role = Role::create(["name" => $this->email . "'s role", "description" => $this->email . "'s admin role", "is_active" => 1,
            "role_service_access_by_role_id" => $this->getRoleServiceAccess($this->tabs)]);
        $this->roleId = $role["id"];
    }

    /**
     * Update role and its service accesses.
     *
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \Exception
     */
    public function updateRestrictedAdminRole()
    {
        $role = $this->getRole();
        $this->validateCurrentUser();
        if (isset($role["id"])) {
            $this->roleId = $role["id"];
            $roleData = Role::selectById($role["id"], ["related" => "role_service_access_by_role_id"]);
            $roleData["role_service_access_by_role_id"] = $this->getUpdatedRoleAccesses($roleData["role_service_access_by_role_id"], $role["id"]);
            Role::updateById($role["id"], $roleData);
        } else {
            $this->createRestrictedAdminRole();
        }
    }

    /**
     * Delete role by user id.
     *
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \Exception
     */
    public function deleteRole()
    {
        $role = $this->getRole();
        if ($role && $role["id"] > 0) {
            Role::deleteById($role["id"]);
            \Cache::forget('role:' . $role["id"]);
            \Cache::flush();
        }
    }

    /**
     * Returns user_to_app_to_role_by_user_id array to links Admin with new role.
     *
     * @param $isRestrictedAdmin
     * @param int $userId
     * @return void
     */
    public function handleUserAppRole($isRestrictedAdmin, $userId = 0)
    {
        // Links role to api docs and file manager apps if the tabs are specified
        $isApiDocsTabSpecified = isset($this->tabs) && in_array("apidocs", $this->tabs);
        $isFilesTabSpecified = isset($this->tabs) && in_array("files", $this->tabs);

        $this->handleUserAppRoleRelation($isRestrictedAdmin, $userId, "admin");
        $this->handleUserAppRoleRelation($isApiDocsTabSpecified, $userId, "api_docs");
        $this->handleUserAppRoleRelation($isFilesTabSpecified, $userId, "file_manager");
    }

    /**
     * Check if provided array contains all accessible tabs.
     *
     * @param array $tabs
     * @return bool
     */
    public static function isAllTabs(array $tabs)
    {
        $allAccessibleTabs = array_keys(self::getTabsAccessesMap(false));

        return $allAccessibleTabs == $tabs;
    }

    /**
     * Get accessible tabs for UI
     *
     * @param int $roleId
     * @return array
     */
    public static function getAccessibleTabsByRoleId(int $roleId)
    {
        $tabsAccessesMap = self::getTabsAccessesMap(false);
        $userAppRole = UserAppRole::whereRoleId($roleId)->get()->toArray();
        if (count($userAppRole) === 0) {
            return array_keys($tabsAccessesMap);
        }
        $roleServiceAccess = RoleServiceAccess::whereRoleId($roleId)->get()->toArray();

        foreach ($tabsAccessesMap as $tabName => $tabAccesses) {
            if (!self::hasAccessToTab($roleServiceAccess, $tabAccesses)) {
                $tabsAccessesMap = self::removeTab($tabsAccessesMap, $tabName);
            }
        }

        $tabs = array_keys($tabsAccessesMap);

        return $tabs;
    }

    /**
     * Get role by name.
     *
     * @return bool
     */
    public function getRole()
    {
        $roles = Role::whereName($this->email . "'s role")->get()->toArray();
        if (count($roles) > 0) {
            return $roles[0];
        } else {
            return null;
        }
    }

    /**
     * Creates tab to role service accesses mapper
     *
     * @param bool $withDefault
     * @return array
     */
    private static function getTabsAccessesMap($withDefault = true)
    {
        $map = array(
            "apps" => array(
                array("component" => "app/*", "verbMask" => VerbsMask::getFullAccessMask()),
                array("component" => "service/*", "verbMask" => VerbsMask::GET_MASK),
                array("component" => "role/*", "verbMask" => VerbsMask::GET_MASK)
            ),
            "users" => array(
                array("component" => "user/*", "verbMask" => VerbsMask::getFullAccessMask()),
                array("component" => "role/*", "verbMask" => VerbsMask::GET_MASK),
                array("component" => "app/*", "verbMask" => VerbsMask::GET_MASK)
            ),
            "roles" => array(
                array("component" => "role/*", "verbMask" => VerbsMask::GET_MASK)
            ),
            "services" => array(
                array("component" => "service_type/", "verbMask" => VerbsMask::getFullAccessMask()),
                array("component" => "service/*", "verbMask" => VerbsMask::getFullAccessMask())
            ),
            "apidocs" => array(
                array("component" => "*", "verbMask" => VerbsMask::getFullAccessMask(), "serviceName" => "api_docs")
            ),
            "schema/data" => array(
                array("component" => "*", "verbMask" => VerbsMask::getFullAccessMask(), "serviceName" => "db")
            ),
            "files" => array(
                array("component" => "*", "verbMask" => VerbsMask::getFullAccessMask(), "serviceName" => "files"),
                array("component" => "*", "verbMask" => VerbsMask::getFullAccessMask(), "serviceName" => "logs"),
            ),
            "scripts" => array(
                array("component" => "event/*", "verbMask" => VerbsMask::getFullAccessMask()),
                array("component" => "event_script/*", "verbMask" => VerbsMask::getFullAccessMask()),
                array("component" => "script_type/*", "verbMask" => VerbsMask::getFullAccessMask())
            ),
            "config" => array(
                array("component" => "custom/*", "verbMask" => VerbsMask::getFullAccessMask()),
                array("component" => "cache/*", "verbMask" => VerbsMask::getFullAccessMask()),
                array("component" => "cors/*", "verbMask" => VerbsMask::getFullAccessMask()),
                array("component" => "email_template/*", "verbMask" => VerbsMask::getFullAccessMask()),
                array("component" => "lookup/*", "verbMask" => VerbsMask::getFullAccessMask()),
                array("component" => "", "verbMask" => VerbsMask::GET_MASK),
                array("component" => "*", "verbMask" => VerbsMask::getFullAccessMask(), "serviceName" => "logs"),
                array("component" => "*", "verbMask" => VerbsMask::getFullAccessMask(), "serviceName" => "email")
            ),
            "packages" => array(
                array("component" => "package/*", "verbMask" => VerbsMask::getFullAccessMask()),
                array("component" => "app/*", "verbMask" => VerbsMask::GET_MASK),
                array("component" => "*", "verbMask" => VerbsMask::GET_MASK | VerbsMask::POST_MASK, "serviceName" => "logs"),
                array("component" => "*", "verbMask" => VerbsMask::GET_MASK | VerbsMask::POST_MASK, "serviceName" => "files"),
                array("component" => "*", "verbMask" => VerbsMask::GET_MASK, "serviceName" => "system"),
            ),
            "limits" => array(
                array("component" => "limit/*", "verbMask" => VerbsMask::getFullAccessMask()),
                array("component" => "limit_cache/*", "verbMask" => VerbsMask::getFullAccessMask()),
                array("component" => "user/", "verbMask" => VerbsMask::GET_MASK),
                array("component" => "role/", "verbMask" => VerbsMask::GET_MASK),
                array("component" => "", "verbMask" => VerbsMask::GET_MASK),
                array("component" => "service/", "verbMask" => VerbsMask::GET_MASK)
            ),
            "scheduler" => array(
                array("component" => "scheduler/*", "verbMask" => VerbsMask::getFullAccessMask()),
                array("component" => "", "verbMask" => VerbsMask::GET_MASK)
            ),
            "default" => array(
                array("component" => "role/*", "verbMask" => VerbsMask::GET_MASK),
                array("component" => "", "verbMask" => VerbsMask::GET_MASK),
                array("component" => "admin/*", "verbMask" => VerbsMask::GET_MASK),
                array("component" => "admin/profile", "verbMask" => VerbsMask::getFullAccessMask()),
                array("component" => "admin/password", "verbMask" => VerbsMask::getFullAccessMask())
            )
        );
        if ($withDefault == false) {
            return Arr::except($map, ["default"]);
        }
        return $map;
    }

    /**
     * Get service id by service name.
     *
     * @param string $name
     * @return array
     */
    private static function getServiceIdByName(string $name)
    {
        return ServiceManager::getServiceIdByName($name);
    }

    /**
     * Get service name or default 'system'
     *
     * @param array $access
     * @return int
     */
    private static function getServiceName(array $access)
    {
        return $access["serviceName"] ?? self::SYSTEM_SERVICE_NAME;
    }

    /**
     * Does role provide access to the tab.
     *
     * @param array $roleServiceAccesses
     * @param array $tabAccess
     * @return bool
     */
    private static function hasAccessToTab(array $roleServiceAccesses, array $tabAccess)
    {
        $hasAccess = true;
        foreach ($tabAccess as $access) {
            if (!self::hasAccessToServiceComponent($roleServiceAccesses, $access)) {
                $hasAccess = false;
            }
        }

        return $hasAccess;
    }

    /**
     * Check role service access.
     *
     * @param array $roleServiceAccesses
     * @param array $serviceComponentAccess
     * @return bool
     */
    private static function hasAccessToServiceComponent(array $roleServiceAccesses, array $serviceComponentAccess)
    {
        return boolval(Arr::first($roleServiceAccesses, function ($roleAccess) use ($serviceComponentAccess) {
            return $roleAccess["component"] === $serviceComponentAccess["component"] &&
                $roleAccess["verb_mask"] === $serviceComponentAccess["verbMask"] &&
                $roleAccess["service_id"] === ServiceManager::getServiceIdByName(self::getServiceName($serviceComponentAccess));

        }));
    }

    /**
     * Hide a tab by name
     *
     * @param array $tabs
     * @param string $tabName
     * @return array
     */
    private static function removeTab(array $tabs, string $tabName)
    {
        return Arr::except($tabs, $tabName);
    }

    /**
     * Get role service access for given tabs
     *
     * @param array $tabs
     * @param bool $withDefault
     * @param bool $withTabs
     * @param bool $unique
     * @return array
     * @throws \Exception
     */
    private function getRoleServiceAccess($tabs = [], $withDefault = true, $withTabs = false, $unique = true)
    {
        $roleServiceAccess = array();

        if ($withDefault) {
            $roleServiceAccess = $this->getTabServiceAccesses($roleServiceAccess, "default", $withDefault, $withTabs, $unique);
        }

        foreach ($tabs as $tab) {
            $roleServiceAccess = $this->getTabServiceAccesses($roleServiceAccess, $tab, $withDefault, $withTabs, $unique);
        }

        return $roleServiceAccess;
    }

    /**
     * Get role service accesses for particular tab
     *
     * @param array $roleServiceAccess
     * @param $withDefault
     * @param $tab
     * @param $unique
     * @param $withTabs
     * @return array
     * @throws \Exception
     */
    private function getTabServiceAccesses($roleServiceAccess, $tab, $withDefault, $withTabs, $unique)
    {
        $map = self::getTabsAccessesMap($withDefault);

        foreach ($this->getTabServicesAccess($map[$tab]) as $access) {
            if ($unique) {
                if ($withTabs) $roleServiceAccess[$tab][] = $this->array_push_unique($access, $roleServiceAccess);
                else $roleServiceAccess = $this->array_push_unique($access, $roleServiceAccess);
            } else {
                if ($withTabs) $roleServiceAccess[$tab][] = $access;
                else $roleServiceAccess[] = $access;
            }
        }

        return $roleServiceAccess;
    }

    /**
     * Delete previous role service access
     *
     * @param array $deleteTabs
     * @param array $roleServiceAccess
     * @param array $accessibleTabs
     * @return array
     * @throws \Exception
     */
    private function deleteRoleServiceAccesses(array $deleteTabs, array $roleServiceAccess, array $accessibleTabs)
    {
        $deleteTabsServiceAccesses = $this->getRoleServiceAccess($deleteTabs, false);
        $serviceAccessesToDelete = $this->getRoleServiceAccessesForDeletion($deleteTabsServiceAccesses, $roleServiceAccess, $accessibleTabs);

        foreach ($serviceAccessesToDelete as $deleteAccess) {
            foreach ($roleServiceAccess as $key => $access) {
                if ($access === $deleteAccess) {
                    $roleServiceAccess = $this->removeAccess($roleServiceAccess, $key);
                }
            }
        }

        return $roleServiceAccess;
    }

    /**
     * Set role_id null, so DF deletes it
     * @param $accesses
     * @param $key
     * @return array
     */
    private function removeAccess($accesses, $key)
    {
        if (isset($accesses[$key]["id"]) && isset($accesses[$key]["role_id"])) {
            $accesses[$key]["role_id"] = null;
            return $accesses;
        } else {
            unset($accesses[$key]);
        }
        return $accesses;
    }

    /**
     * Determine which role service accesses to remove.
     *
     * @param $deleteTabsServiceAccesses
     * @param $roleServiceAccess
     * @param $accessibleTabs
     * @return array
     * @throws \Exception
     */
    private function getRoleServiceAccessesForDeletion($deleteTabsServiceAccesses, $roleServiceAccess, $accessibleTabs)
    {
        $accessesToDelete = [];

        foreach ($deleteTabsServiceAccesses as $deleteAccess) {
            $existingAccessesToDelete = array_filter($roleServiceAccess, function ($roleAccess) use ($deleteAccess) {
                return $this->compareAccess($roleAccess, $deleteAccess);
            });
            if (count($existingAccessesToDelete) > 0) $accessesToDelete = array_merge($accessesToDelete, $existingAccessesToDelete);
        }

        $accessesToDelete = $this->filterDeleteAccesses($accessesToDelete, $accessibleTabs);
        return $accessesToDelete;
    }

    /**
     * Compare two role service accesses.
     *
     * @param $access1
     * @param $access2
     * @return bool
     */
    private function compareAccess($access1, $access2)
    {
        return $access1["component"] === $access2["component"] && $access1["service_id"] === $access2["service_id"] && $access1["verb_mask"] === $access2["verb_mask"] && $access1["requestor_mask"] === $access2["requestor_mask"];
    }

    /**
     * Add role service access if there is no such one already defined for this role.
     *
     * @param $accesses
     * @param $accessesToAdd
     * @return array
     */
    private function addServiceAccess($accesses, $accessesToAdd)
    {
        foreach ($accessesToAdd as $key => $addAccess) {
            $exist = false;
            foreach ($accesses as $access) {
                if ($this->compareAccess($access, $addAccess)) {
                    $exist = true;
                }
            }
            if (!$exist) {
                $accesses[] = $addAccess;
            }
        }
        return $accesses;
    }

    /**
     * Filter service accesses that are used for other tabs.
     *
     * @param $accessesToDelete
     * @param $accessibleTabs
     * @return array
     * @throws \Exception
     */
    private function filterDeleteAccesses($accessesToDelete, $accessibleTabs)
    {
        $result = [];
        $roleServiceAccessWithTabs = $this->getRoleServiceAccess($accessibleTabs, true, true, false);

        foreach ($accessesToDelete as $deleteAccess) {
            $existCounter = 0;
            foreach ($roleServiceAccessWithTabs as $tab => $tabAccesses) {
                foreach ($tabAccesses as $access) {
                    if ($this->compareAccess($access, $deleteAccess)) {
                        $existCounter++;
                    }
                }
            }
            if ($existCounter === 1) $result[] = $deleteAccess;
        }
        return $result;
    }

    /**
     * Get role_service_access_by_role_id array to be added to the update admin request.
     *
     * @param array $roleServiceAccesses
     * @param $roleId
     * @return array
     * @throws \Exception
     */
    private function getUpdatedRoleAccesses(array $roleServiceAccesses, $roleId)
    {
        $accessibleTabs = self::getAccessibleTabsByRoleId($roleId);
        $tabsToAdd = array_diff($this->tabs, $accessibleTabs);
        $tabsToDelete = array_diff($accessibleTabs, $this->tabs);
        $roleServiceAccesses = $this->deleteRoleServiceAccesses($tabsToDelete, $roleServiceAccesses, $accessibleTabs);
        $roleServiceAccesses = $this->addServiceAccess($roleServiceAccesses, $this->getRoleServiceAccess($tabsToAdd, false));
        return $roleServiceAccesses;
    }

    /**
     * Push role service access if exact same access is not in array.
     *
     * @param $access
     * @param array $roleServiceAccess
     * @return array
     */
    private function array_push_unique($access, array $roleServiceAccess)
    {
        if (!in_array($access, $roleServiceAccess)) {
            array_push($roleServiceAccess, $access);
        }
        return $roleServiceAccess;
    }

    /**
     * Get a role service accesses to be created for the role.
     *
     * @param array $params
     * @return array
     * @throws \Exception
     */
    private function getTabServicesAccess(array $params)
    {
        $result = array();
        foreach ($params as $access) {
            array_push($result, [
                "service_id" => self::getServiceIdByName(self::getServiceName($access)),
                "component" => $access["component"],
                "verb_mask" => $access["verbMask"],
                "requestor_mask" => ServiceRequestorTypes::getAllRequestorTypes(),
                "filters" => [],
                "filter_op" => "AND"]);
        }
        return $result;
    }

    /**
     * Get App ID by its name.
     *
     * @param string $appName
     * @return mixed
     */
    private function getAppIdByName(string $appName)
    {
        return App::whereName($appName)->first()["id"];
    }

    /**
     * Get userAppRole array to be added to the request for this relation to be created.
     *
     * @param string $appName
     * @param $userId
     * @return mixed
     */
    private function getUserAppRoleLink(string $appName, $userId)
    {
        return ["app_id" => $this->getAppIdByName($appName), "role_id" => $this->roleId, "user_id" => $userId];
    }

    /**
     * Check User App Role records existence .
     *
     * @param array $userAppRoleData
     * @return mixed
     */
    private function doesUserAppRoleExist(array $userAppRoleData)
    {
        return UserAppRole::whereUserId($userAppRoleData["user_id"])->whereAppId($userAppRoleData["app_id"])->whereRoleId($userAppRoleData["role_id"])->exists();
    }

    /**
     * Set user_id to null so it will be deleted.
     *
     * @param array $userAppRoleData
     */
    private function deleteUserAppRoleLink(array $userAppRoleData)
    {
        $userAppRole = UserAppRole::whereUserId($userAppRoleData["user_id"])->whereAppId($userAppRoleData["app_id"])->whereRoleId($userAppRoleData["role_id"])->first()->toArray();
        UserAppRole::deleteById($userAppRole['id']);
    }

    /**
     * Validate current user access
     *
     * @throws ForbiddenException
     */
    private function validateCurrentUser()
    {
        if (Session::hasRole()) {
            throw new ForbiddenException('RestrictedAdmins are not allowed to edit access by tabs.');
        };
    }

    /**
     * Delete or create userAppRole relation
     *
     * @param $isSpecified
     * @param $userId
     * @param $appName
     */
    private function handleUserAppRoleRelation($isSpecified, $userId, $appName)
    {
        $userAppRoleLink = $this->getUserAppRoleLink($appName, $userId);
        $doesLinkExist = $this->doesUserAppRoleExist($userAppRoleLink);

        if ($isSpecified && !$doesLinkExist) {
            UserAppRole::createInternal($userAppRoleLink);
        } elseif (!$isSpecified && $doesLinkExist) {
            $this->deleteUserAppRoleLink($userAppRoleLink);
        }
    }
}