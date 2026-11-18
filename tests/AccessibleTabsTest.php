<?php

namespace DreamFactory\Core\Testing;

use DreamFactory\Core\Compliance\Http\Middleware\AccessibleTabs;
use DreamFactory\Core\Compliance\Models\AdminUser;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Core\Utility\JWTUtilities;
use DreamFactory\Core\Models\App;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use \Mockery as m;

class AccessibleTabsTest extends TestCase
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

    private $role = [
        'id' => 0
    ];

    public function tearDown(): void
    {
        AdminUser::whereEmail('jdoe@dreamfactory.com')->delete();
        parent::tearDown();
    }

    public function testAccessibleTabs()
    {
        $rootAdminUser = AdminUser::create($this->adminData);
        Session::setUserInfoWithJWT($rootAdminUser);
        $token = JWTUtilities::makeJWTByUser($rootAdminUser->id, $rootAdminUser->email);
        $apiKey = App::find(1)->api_key;
        $rq = Request::create("http://localhost/api/v2/system/role", "GET", ['accessible_tabs' => true], [], [], [], []);
        $rq->headers->set('HTTP_X_DREAMFACTORY_SESSION_TOKEN', $token);
        $rq->headers->set('X-Dreamfactory-API-Key', $apiKey);
        $rq->headers->set('accessible_tabs', true);
        $rq->setRouteResolver(function () use ($rq) {
            return (new Route('GET', 'api/{version}/{service}/{resource?}', []))->bind($rq);
        });
        $response = m::mock('Illuminate\Http\Response')->shouldReceive('getOriginalContent')->once()->andReturn(['id' => $this->role['id']])->getMock();
        $response->shouldReceive('setContent')->with(['id' => 0, 'accessible_tabs' => [
            0 => "apps",
            1 => "users",
            2 => "roles",
            3 => "services",
            4 => "apidocs",
            5 => "schema/data",
            6 => "files",
            7 => "scripts",
            8 => "config",
            9 => "packages",
            10 => "limits",
            11 => "scheduler"
        ]]);
        $middleware = new AccessibleTabs();
        $middleware->handle($rq, function () use ($response) {
            return $response;
        });
    }
}