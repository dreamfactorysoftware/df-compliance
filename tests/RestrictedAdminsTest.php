<?php

namespace DreamFactory\Core\Testing;

use DreamFactory\Core\Compliance\Http\Middleware\HandleRestrictedAdminRole;
use DreamFactory\Core\Compliance\Http\Middleware\HandleRestrictedAdmin;
use DreamFactory\Core\Compliance\Components\RestrictedAdmin;
use DreamFactory\Core\Compliance\Enums\VerbsMask;
use DreamFactory\Core\Compliance\Models\AdminUser;
use DreamFactory\Core\Models\Role;
use DreamFactory\Core\Models\RoleServiceAccess;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Core\Utility\JWTUtilities;
use DreamFactory\Core\Models\App;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use DreamFactory\Core\Exceptions\ForbiddenException;
use \Mockery as m;
use ServiceManager;

class RestrictedAdminsTest extends TestCase
{
    private $rootAdminData = [
        'name' => 'John Doe',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'jdoe@dreamfactory.com',
        'password' => 'test1234',
        'security_question' => 'Make of your first car?',
        'security_answer' => 'mazda',
        'is_active' => true,
        'is_sys_admin' => true,
        'is_root_admin' => true
    ];

    private $raAdminData = [
        'name' => 'John_Doe',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'jdoeRA@dreamfactory.com',
        'password' => 'test1234',
        'security_question' => 'Make of your first car?',
        'security_answer' => 'mazda',
        'is_sys_admin' => true,
        'is_active' => true,
    ];

    private $adminRequstData = [
        'email' => 'test@dreamfactory.com',
        'password' => 'test1234',
        'security_question' => 'Your favourite band?',
        'security_answer' => 'The Pretty Reckless',
        'is_active' => true,
        'is_restricted_admin' => true,
        'access_by_tabs' => ['users']
    ];

    private $nonRootAdminData = [
        'name' => 'John Doe',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'jdoeNonRoot@dreamfactory.com',
        'password' => 'test1234',
        'security_question' => 'Make of your first car?',
        'security_answer' => 'mazda',
        'is_active' => true
    ];

    public function tearDown(): void
    {
        AdminUser::whereEmail('jdoe@dreamfactory.com')->delete();
        AdminUser::whereEmail('jdoeRA@dreamfactory.com')->delete();
        AdminUser::whereEmail('jdoeNonRoot@dreamfactory.com')->delete();
        Role::whereName('jdoeRA@dreamfactory.com\'s role')->delete();
        parent::tearDown();
    }

    public function testRestrictedAdminCreation()
    {
        $role = $this->createRARole();
        $roleId = $role->id;
        $this->assertTrue(1 === Role::whereName($this->raAdminData['email'] . '\'s role')->count());
        $this->assertTrue(7 === RoleServiceAccess::whereRoleId($roleId)->count());
    }

    public function testOnlyRootAdminCanModifyRARole()
    {
        $role = $this->createRARole();
        $this->assertTrue(1 === Role::whereName($this->raAdminData['email'] . '\'s role')->count());
        $this->assertTrue(7 === RoleServiceAccess::whereRoleId($role->id)->count());

        // Modify RA role
        $restrictedAdmin = AdminUser::whereEmail($this->raAdminData['email'])->first();
        $apiKey = App::find(1)->api_key;
        Session::setUserInfoWithJWT($restrictedAdmin);
        $token = JWTUtilities::makeJWTByUser($restrictedAdmin->id, $restrictedAdmin->email);
        $rq = Request::create("http://localhost/api/v2/system/role/" . $role->id, "PUT", ['id' => $role->id,], [], [], [], []);
        $rq->headers->set('HTTP_X_DREAMFACTORY_SESSION_TOKEN', $token);
        $rq->headers->set('X-Dreamfactory-API-Key', $apiKey);
        $rq->setRouteResolver(function () use ($rq) {
            return (new Route('PUT', 'api/{version}/{service}/{resource?}', []))
                ->where(['resource' => '.*'])
                ->bind($rq);
        });
        $nextCalled = false;
        $middleware = new HandleRestrictedAdminRole();
        $response = $middleware->handle($rq, function () use (&$nextCalled) {
            $nextCalled = true;
        });
        $this->assertFalse($nextCalled);
        $this->assertEquals(403, $response->getStatusCode());
        $this->assertEquals('You do not have permission to modify restricted admin roles. Please contact your root administrator.',
            json_decode($response->getContent(), true)['error']['message']);
    }

    public function testNonRootAdminsCannotManageAdmins()
    {
        AdminUser::create($this->nonRootAdminData);
        $nonRootAdmin = AdminUser::whereEmail($this->nonRootAdminData['email'])->first();
        $nonRootAdmin->is_sys_admin = true;
        $nonRootAdmin->save();
        Session::setUserInfoWithJWT($nonRootAdmin);
        $response = $this->get('/api/v2/system/admin');
        $response->assertStatus(403);
        $this->assertEquals('Only root admin can manage admins.', $response->json('error.message'));

        $response = $this->get('/api/v2/system/admin/profile');
        $response->assertStatus(200);

        $role = $this->createRARole();
        Session::setUserInfoWithJWT($nonRootAdmin);
        $response = $this->delete('/api/v2/system/role/' . $role->id);
        $response->assertStatus(403);
        $this->assertEquals('You do not have permission to modify restricted admin roles. Please contact your root administrator.', $response->json('error.message'));

        Session::setUserInfoWithJWT($nonRootAdmin);
        $response = $this->json('PATCH', '/api/v2/system/role', ['resource' => [['id' => $role->id, 'description' => 'changed']]]);
        $response->assertStatus(403);
        $this->assertEquals('You do not have permission to modify restricted admin roles. Please contact your root administrator.', $response->json('error.message'));

        Session::setUserInfoWithJWT($nonRootAdmin);
        $response = $this->json('PATCH', '/api/v2/system/role', [['id' => $role->id, 'description' => 'changed']]);
        $response->assertStatus(403);
        $this->assertEquals('You do not have permission to modify restricted admin roles. Please contact your root administrator.', $response->json('error.message'));
    }

    /**
     * The root-admin authorization must gate the downstream handler.
     * A non-root admin POSTing to system/admin must be rejected BEFORE $next
     * runs, so the handler never commits an admin row.
     */
    public function testNonRootAdminRejectedBeforeHandlerRuns()
    {
        AdminUser::create($this->nonRootAdminData);
        $nonRootAdmin = AdminUser::whereEmail($this->nonRootAdminData['email'])->first();
        $nonRootAdmin->is_sys_admin = true;
        $nonRootAdmin->save();
        Session::setUserInfoWithJWT($nonRootAdmin);
        $token = JWTUtilities::makeJWTByUser($nonRootAdmin->id, $nonRootAdmin->email);
        $apiKey = App::find(1)->api_key;

        $rq = Request::create("http://localhost/api/v2/system/admin", "POST", $this->adminRequstData, [], [], [], []);
        $rq->headers->set('HTTP_X_DREAMFACTORY_SESSION_TOKEN', $token);
        $rq->headers->set('X-Dreamfactory-API-Key', $apiKey);
        $rq->setRouteResolver(function () use ($rq) {
            return (new Route('POST', 'api/{version}/{service}/{resource?}', []))->bind($rq);
        });

        $nextCalled = false;
        $middleware = new HandleRestrictedAdmin();
        $response = $middleware->handle($rq, function () use (&$nextCalled) {
            $nextCalled = true;
            return m::mock('Illuminate\Http\Response');
        });

        // The handler ($next) must never run: the write is prevented, not undone.
        $this->assertFalse($nextCalled, 'Downstream handler ran before authorization; write would commit.');
        $this->assertEquals(403, $response->getStatusCode());
        $this->assertEquals('Only root admin can manage admins.', json_decode($response->getContent(), true)['error']['message']);
    }

    /**
     * The path match must not depend on the Host header, letter case or
     * percent-encoding. Each of these URLs reaches the admin resource.
     */
    public function testAdminPathMatchIgnoresHostCaseAndEncoding()
    {
        $nonRootAdmin = $this->makeAdmin($this->nonRootAdminData);
        Session::setUserInfoWithJWT($nonRootAdmin);

        $urls = [
            'http://session.localhost/api/v2/system/admin',
            'http://password.localhost/api/v2/system/admin',
            'http://localhost/api/v2/SYSTEM/admin',
            'http://localhost/api/v2/system/%61dmin',
        ];
        foreach ($urls as $url) {
            $rq = Request::create($url, 'POST', $this->adminRequstData);
            $nextCalled = false;
            $response = (new HandleRestrictedAdmin())->handle($rq, function () use (&$nextCalled) {
                $nextCalled = true;
                return m::mock('Illuminate\Http\Response');
            });
            $this->assertFalse($nextCalled, "Handler ran for $url");
            $this->assertEquals(403, $response->getStatusCode(), "Not rejected: $url");
        }
    }

    /**
     * The model guard holds on every route, because it does not look at the
     * route. Only a root admin may create, change or delete an admin.
     */
    public function testModelGuardAllowsOnlyRootToManageAdmins()
    {
        $root = $this->makeAdmin($this->rootAdminData, true);
        $nonRoot = $this->makeAdmin($this->nonRootAdminData);
        $other = $this->makeAdmin($this->raAdminData);

        $denied = function (callable $change) {
            try {
                $change();
            } catch (ForbiddenException $e) {
                return true;
            }
            return false;
        };
        $newAdmin = function () {
            $user = new \DreamFactory\Core\Models\User();
            $user->is_sys_admin = true;
            return $user;
        };

        Session::setUserInfoWithJWT($nonRoot);
        $this->assertTrue($denied(function () use ($newAdmin) {
            AdminUser::authorizeChange($newAdmin());
        }), 'non-root created an admin');
        $this->assertTrue($denied(function () use ($other) {
            $other->first_name = 'changed';
            AdminUser::authorizeChange($other);
        }), 'non-root changed another admin');
        $this->assertTrue($denied(function () use ($other) {
            AdminUser::authorizeChange($other->fresh(), true);
        }), 'non-root deleted another admin');
        $this->assertTrue($denied(function () use ($nonRoot) {
            $self = $nonRoot->fresh();
            $self->is_root_admin = true;
            AdminUser::authorizeChange($self);
        }), 'non-root made itself root');
        $this->assertFalse($denied(function () use ($nonRoot) {
            $self = $nonRoot->fresh();
            $self->first_name = 'mine';
            AdminUser::authorizeChange($self);
        }), 'non-root could not edit its own record');

        Session::setUserInfoWithJWT($root);
        $this->assertFalse($denied(function () use ($newAdmin) {
            AdminUser::authorizeChange($newAdmin());
        }), 'root could not create an admin');
        $this->assertFalse($denied(function () use ($other) {
            AdminUser::authorizeChange($other->fresh(), true);
        }), 'root could not delete an admin');
    }

    private function makeAdmin(array $data, $root = false)
    {
        AdminUser::create($data);
        $admin = AdminUser::whereEmail($data['email'])->first();
        $admin->is_sys_admin = true;
        $admin->is_root_admin = $root;
        $admin->save();

        return $admin;
    }

    public function testConfigTabGrantsServerSettingAccess()
    {
        $role = $this->createRARole(['config']);
        $access = RoleServiceAccess::whereRoleId($role->id)
            ->whereServiceId(ServiceManager::getServiceIdByName('system'))
            ->whereComponent('server_setting/*')
            ->first();
        $this->assertNotNull($access);
        $this->assertEquals(VerbsMask::getFullAccessMask(), $access->verb_mask);
    }

    public function testRoleWithoutServerSettingRowKeepsConfigTab()
    {
        // a restricted admin created before server_setting/* joined the Config tab
        $role = $this->createRARole(['config']);
        RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->delete();
        $this->assertContains('config', RestrictedAdmin::getAccessibleTabsByRoleId($role->id));
    }

    public function testResaveGrantsServerSettingToExistingConfigAdmin()
    {
        $role = $this->createRARole(['config']);
        RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->delete();
        // a root admin narrowed one row of the tab; a re-save must not add the wide one back
        RoleServiceAccess::whereRoleId($role->id)->whereComponent('cors/*')->update(['requestor_mask' => 1]);

        // root admin saves the admin again with the same tabs
        $root = AdminUser::whereEmail($this->rootAdminData['email'])->first();
        Session::setUserInfo(['id' => $root->id, 'email' => $root->email, 'is_sys_admin' => true]);
        (new RestrictedAdmin($this->raAdminData['email'], ['config']))->updateRestrictedAdminRole();

        $this->assertSame(1, RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->count());
        $this->assertSame(1, RoleServiceAccess::whereRoleId($role->id)->whereComponent('cors/*')->count());
    }

    public function testResaveKeepsNarrowedServerSettingRow()
    {
        $role = $this->createRARole(['config']);
        RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->update(['verb_mask' => 1]);

        $root = AdminUser::whereEmail($this->rootAdminData['email'])->first();
        Session::setUserInfo(['id' => $root->id, 'email' => $root->email, 'is_sys_admin' => true]);
        (new RestrictedAdmin($this->raAdminData['email'], ['config']))->updateRestrictedAdminRole();

        $rows = RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int)$rows[0]->verb_mask);
    }

    public function testRemovingConfigTabRevokesServerSettingAccess()
    {
        $role = $this->createRARole(['config', 'users']);
        $this->assertSame(1, RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->count());

        // root admin saves the admin again without the Config tab
        $root = AdminUser::whereEmail($this->rootAdminData['email'])->first();
        Session::setUserInfo(['id' => $root->id, 'email' => $root->email, 'is_sys_admin' => true]);
        (new RestrictedAdmin($this->raAdminData['email'], ['users']))->updateRestrictedAdminRole();

        $this->assertSame(0, RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->count());
        $tabs = RestrictedAdmin::getAccessibleTabsByRoleId($role->id);
        $this->assertNotContains('config', $tabs);
        $this->assertContains('users', $tabs);
    }

    public function testTabsWithoutConfigNeverGrantServerSetting()
    {
        $role = $this->createRARole(['apps', 'users', 'services', 'scripts', 'limits', 'scheduler']);
        $this->assertSame(0, RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->count());
    }

    public function createRARole(array $tabs = ['users'])
    {
        // Create a RA role
        $rootAdmin = AdminUser::create($this->rootAdminData);
        $rootAdmin->is_root_admin = true;
        $rootAdmin->is_sys_admin = true;
        $rootAdmin->save();
        $restrictedAdmin = AdminUser::create($this->raAdminData);
        $restrictedAdmin->is_sys_admin = true;
        $restrictedAdmin->save();
        Session::setUserInfoWithJWT($rootAdmin);
        $token = JWTUtilities::makeJWTByUser($rootAdmin->id, $rootAdmin->email);
        $apiKey = App::find(1)->api_key;
        $rq = Request::create("http://localhost/api/v2/system/admin", "POST", ['access_by_tabs' => $tabs] + $this->adminRequstData, [], [], [], []);
        $rq->headers->set('HTTP_X_DREAMFACTORY_SESSION_TOKEN', $token);
        $rq->headers->set('X-Dreamfactory-API-Key', $apiKey);
        $rq->setRouteResolver(function () use ($rq) {
            return (new Route('POST', 'api/{version}/{service}/{resource?}', []))->bind($rq);
        });
        $response = m::mock('Illuminate\Http\Response')->shouldReceive('getOriginalContent')->once()->andReturn(['id' => $restrictedAdmin->id])->getMock();
        $response->shouldReceive('isSuccessful')->once()->andReturn(true);
        $middleware = new HandleRestrictedAdmin();
        $middleware->handle($rq, function () use ($response) {
            return $response;
        });
        $role = Role::whereName($this->raAdminData['email'] . '\'s role')->first();
        return $role;
    }
}