<?php

namespace DreamFactory\Core\Testing;

use DreamFactory\Core\Compliance\Http\Middleware\HandleRestrictedAdminRole;
use DreamFactory\Core\Compliance\Http\Middleware\HandleRestrictedAdmin;
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

class RestrictedAdminsTest extends TestCase
{
    // is_restricted_admin,access_by_tabs,is_restricted_admin,email

//    rescontent = id


    public function tearDown()
    {
        AdminUser::whereEmail('jdoe@dreamfactory.com')->delete();
        AdminUser::whereEmail('jdoeRA@dreamfactory.com')->delete();
        Role::whereName('jdoeRA@dreamfactory.com\'s role')->delete();
        parent::tearDown();
    }

    public function testRestrictedAdminCreation()
    {
        $rootAdminData = [
            'name' => 'John Doe',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'jdoe@dreamfactory.com',
            'password' => 'test1234',
            'security_question' => 'Make of your first car?',
            'security_answer' => 'mazda',
            'is_active' => true,
            'is_root_admin' => true
        ];
        $raAdminData = [
            'name' => 'John_Doe',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'jdoeRA@dreamfactory.com',
            'password' => 'test1234',
            'security_question' => 'Make of your first car?',
            'security_answer' => 'mazda',
            'is_active' => true,
        ];
        $adminRequstData = [
            'email' => 'test@dreamfactory.com',
            'password' => 'test1234',
            'security_question' => 'Your favourite band?',
            'security_answer' => 'The Pretty Reckless',
            'is_active' => true,
            'is_restricted_admin' => true,
            'access_by_tabs' => ['users']
        ];

        $rootAdmin = AdminUser::create($rootAdminData);
        $restrictedAdmin = AdminUser::create($raAdminData);
        Session::setUserInfoWithJWT($rootAdmin);
        $token = JWTUtilities::makeJWTByUser($rootAdmin->id, $rootAdmin->email);
        $apiKey = App::find(1)->api_key;
        $rq = Request::create("http://localhost/api/v2/system/admin", "POST", $adminRequstData, [], [], [], []);
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
        $this->assertTrue(1 === Role::whereName($raAdminData['email'] . '\'s role')->count());
        $roleId = Role::whereName($raAdminData['email'] . '\'s role')->first()->id;
        $this->assertTrue(7 === RoleServiceAccess::whereRoleId($roleId)->count());
    }

    public function testOnlyRootAdminCanModifyRARole()
    {
        $rootAdminData = [
            'name' => 'John Doe',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'jdoe@dreamfactory.com',
            'password' => 'test1234',
            'security_question' => 'Make of your first car?',
            'security_answer' => 'mazda',
            'is_active' => true,
            'is_sys_admin'=>true,
            'is_root_admin' => true
        ];
        $raAdminData = [
            'name' => 'John_Doe',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'jdoeRA@dreamfactory.com',
            'password' => 'test1234',
            'security_question' => 'Make of your first car?',
            'security_answer' => 'mazda',
            'is_sys_admin'=>true,
            'is_active' => true,
        ];
        $adminRequstData = [
            'email' => 'test@dreamfactory.com',
            'password' => 'test1234',
            'security_question' => 'Your favourite band?',
            'security_answer' => 'The Pretty Reckless',
            'is_active' => true,
            'is_restricted_admin' => true,
            'access_by_tabs' => ['users']
        ];

        // Create a RA role
        $rootAdmin = AdminUser::create($rootAdminData);
        $rootAdmin->is_root_admin = true;
        $rootAdmin->is_sys_admin = true;
        $rootAdmin->save();
        $restrictedAdmin = AdminUser::create($raAdminData);
        $restrictedAdmin->is_sys_admin = true;
        $restrictedAdmin->save();
        Session::setUserInfoWithJWT($rootAdmin);
        $token = JWTUtilities::makeJWTByUser($rootAdmin->id, $rootAdmin->email);
        $apiKey = App::find(1)->api_key;
        $rq = Request::create("http://localhost/api/v2/system/admin", "POST", $adminRequstData, [], [], [], []);
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
        $this->assertTrue(1 === Role::whereName($raAdminData['email'] . '\'s role')->count());
        $role = Role::whereName($raAdminData['email'] . '\'s role')->first();
        $this->assertTrue(7 === RoleServiceAccess::whereRoleId($role->id)->count());

        // Modify RA role
        Session::setUserInfoWithJWT($restrictedAdmin);
        $token = JWTUtilities::makeJWTByUser($restrictedAdmin->id, $restrictedAdmin->email);
        $rq = Request::create("http://localhost/api/v2/system/role/" . $role->id, "PUT", ['id' => $role->id, ], [], [], [], []);
        $rq->headers->set('HTTP_X_DREAMFACTORY_SESSION_TOKEN', $token);
        $rq->headers->set('X-Dreamfactory-API-Key', $apiKey);
        $routeMock = m::mock(Route::class)
            ->shouldReceive('parameter')
            ->with('resource')
            ->andReturn('role/' . $role->id);
        $rq->setRouteResolver(function () use ($rq, $routeMock) {
            return ($routeMock->getMock());
        });
        try {
            $middleware = new HandleRestrictedAdminRole();
            $middleware->handle($rq, function () {});
        } catch (\Throwable $e) {
        }
        $this->assertEquals(403, $e->getStatusCode());
        $this->assertEquals('You do not have permission to modify restricted admin roles. Please contact your root administrator.', $e->getMessage());

    }
}