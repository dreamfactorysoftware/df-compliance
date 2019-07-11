<?php
namespace DreamFactory\Core\Testing;

use DreamFactory\Core\Compliance\Http\Middleware\ServiceLevelAudit;
use DreamFactory\Core\Compliance\Models\AdminUser;
use DreamFactory\Core\Compliance\Models\ServiceReport;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Core\Utility\JWTUtilities;
use DreamFactory\Core\Models\App;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use \Mockery as Mockery;

class ServiceLevelAuditingTest extends TestCase
{
    public function tearDown()
    {
        AdminUser::whereEmail('jdoe@dreamfactory.com')->delete();
        ServiceReport::whereServiceName('Node-test')->delete();
        parent::tearDown();
    }

    public function testServiceReportCreation()
    {
        $user = [
            'name' => 'John Doe',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'jdoe@dreamfactory.com',
            'password' => 'test1234',
            'security_question' => 'Make of your first car?',
            'security_answer' => 'mazda',
            'is_active' => true
        ];

        $service = ["resource" => [
            [
                "name" => "Node-test",
                "label" => "node",
                "description" => "node",
                "is_active" => true,
                "type" => "nodejs",
                "config" => [
                    "content" => ["",
                        "storage_service_id" => null,
                        "scm_repository" => null,
                        "scm_reference" => null,
                        "storage_path" => null
                    ],
                    "service_doc_by_service_id" => null
                ]
            ]]];
        $nonAdminUser = AdminUser::create($user);
        Session::setUserInfoWithJWT($nonAdminUser);
        $token = JWTUtilities::makeJWTByUser($nonAdminUser->id, $nonAdminUser->email);
        $app = App::find(1);
        $apiKey = $app->api_key;
        $rq = Request::create("http://localhost/api/v2/system/service", "POST", $service, [], [], [], []);
        $rq->headers->set('HTTP_X_DREAMFACTORY_SESSION_TOKEN', $token);
        $rq->headers->set('X-Dreamfactory-API-Key', $apiKey);
        $rq->setRouteResolver(function () use ($rq) {
            return (new Route('POST', 'api/{version}/{service}/{resource?}', []))->bind($rq);
        });
        $response = Mockery::mock('Illuminate\Http\Response')->shouldReceive('getOriginalContent')->once()->andReturn('blah')->getMock();
        $response->shouldReceive('status')->once()->andReturn('blah');
        $middleware = new ServiceLevelAudit();
        $middleware->handle($rq, function () use ($response) {
            return $response;
        });
        $this->assertTrue(1 === ServiceReport::whereServiceName('Node-test')->count());
    }
}