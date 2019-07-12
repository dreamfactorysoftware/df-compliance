<?php

namespace DreamFactory\Core\Testing;

use DreamFactory\Core\Compliance\Http\Middleware\DoesRootAdminExist;
use DreamFactory\Core\Compliance\Http\Middleware\MarkAsRootAdmin;
use DreamFactory\Core\Compliance\Models\AdminUser;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Core\Utility\JWTUtilities;
use DreamFactory\Core\Models\App;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use \Mockery as m;

class RootAdminTest extends TestCase
{
    private $adminData = [
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

    public function tearDown()
    {
        AdminUser::whereEmail('jdoe@dreamfactory.com')->delete();
        parent::tearDown();
    }

    public function testMarkAsRootAdminMiddleware()
    {
        $loginData = ['email' => $this->adminData['email'], 'password' => $this->adminData['password'], 'remember_me' => false];

        $rootAdminUser = AdminUser::create($this->adminData);
        Session::setUserInfoWithJWT($rootAdminUser);
        $token = JWTUtilities::makeJWTByUser($rootAdminUser->id, $rootAdminUser->email);
        $apiKey = App::find(1)->api_key;
        $rq = Request::create("http://localhost/api/v2/system/admin/session", "POST", $loginData, [], [], [], []);
        $rq->headers->set('HTTP_X_DREAMFACTORY_SESSION_TOKEN', $token);
        $rq->headers->set('X-Dreamfactory-API-Key', $apiKey);
        $rq->setRouteResolver(function () use ($rq) {
            return (new Route('POST', 'api/{version}/{service}/{resource?}', []))->bind($rq);
        });
        $response = m::mock('Illuminate\Http\Response')->shouldReceive('getOriginalContent')->once()->andReturn(['id' => AdminUser::whereEmail($this->adminData['email'])->first()->id])->getMock();
        $response->shouldReceive('setContent')->with(['id' => AdminUser::whereEmail($this->adminData['email'])->first()->id, 'is_root_admin' => true]);
        $middleware = new MarkAsRootAdmin();
        $middleware->handle($rq, function () use ($response) {
            return $response;
        });
    }

    public function testDoesRootAdminExist()
    {
        $rootAdminUser = AdminUser::create($this->adminData);
        Session::setUserInfoWithJWT($rootAdminUser);
        $token = JWTUtilities::makeJWTByUser($rootAdminUser->id, $rootAdminUser->email);
        $apiKey = App::find(1)->api_key;
        $rq = Request::create("http://localhost/api/v2/system/environment", "GET", [], [], [], [], []);
        $rq->headers->set('HTTP_X_DREAMFACTORY_SESSION_TOKEN', $token);
        $rq->headers->set('X-Dreamfactory-API-Key', $apiKey);
        $rq->setRouteResolver(function () use ($rq) {
            return (new Route('GET', 'api/{version}/{service}/{resource?}', []))->bind($rq);
        });
        $response = m::mock('Illuminate\Http\Response')->shouldReceive('getOriginalContent')->once()->andReturn(['platform' => []])->getMock();
        $response->shouldReceive('setContent')->with(['platform' => ['root_admin_exists' => true]]);
        $middleware = new DoesRootAdminExist();
        $middleware->handle($rq, function () use ($response) {
            return $response;
        });
    }
}