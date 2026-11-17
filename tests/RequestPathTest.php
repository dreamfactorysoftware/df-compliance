<?php

namespace DreamFactory\Core\Compliance\Tests;

use DreamFactory\Core\Compliance\Http\Middleware\DoesRootAdminExist;
use DreamFactory\Core\Compliance\Http\Middleware\HandleRestrictedAdmin;
use DreamFactory\Core\Compliance\Http\Middleware\HandleRestrictedAdminRole;
use DreamFactory\Core\Compliance\Http\Middleware\ServiceLevelAudit;
use DreamFactory\Core\Compliance\Utility\MiddlewareHelper;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Request handling of the compliance middleware: endpoint resolution, ids and
 * records. Plain unit tests: no database or booted application needed.
 */
class RequestPathTest extends TestCase
{
    /**
     * Services known to the test application (name => type).
     */
    const SERVICES = [
        'system'    => 'system',
        'user'      => 'user',
        'sysconfig' => 'system',
        'AppAdmin'  => 'system',
        'accounts'  => 'user',
        'db'        => 'sql_db',
        'files'     => 'local_file',
    ];

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    /**
     * Bind a minimal application: the given request as the current request,
     * configuration and a service list.
     *
     * @param Request $request
     *
     * @return Request
     */
    private static function serve(Request $request)
    {
        foreach (['Request' => \Illuminate\Support\Facades\Request::class, 'Config' => \Illuminate\Support\Facades\Config::class] as $alias => $facade) {
            if (!class_exists($alias)) {
                class_alias($facade, $alias);
            }
        }

        $container = new Container();
        $container->instance('request', $request);
        $container->instance('config', new Repository(['df' => ['resources_wrapper' => 'resource']]));
        $container->instance('df.service', new class (self::SERVICES) {
            public function __construct(private array $types)
            {
            }

            public function getServiceNameTypeMap()
            {
                return $this->types;
            }
        });
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        return $request;
    }

    /**
     * A routed request with a body, served as the current request.
     *
     * @param string      $path        path below api/v2/, as sent on the wire
     * @param string      $method
     * @param mixed       $body        array (sent as JSON) or raw string
     * @param string|null $contentType
     *
     * @return Request
     */
    private static function serveWithBody($path, $method, $body = null, $contentType = 'application/json')
    {
        $content = is_array($body) ? json_encode($body) : $body;
        $server = $contentType ? ['CONTENT_TYPE' => $contentType] : [];

        return static::serve(static::request($path, $method, true, $matched, $content, $server));
    }

    /**
     * The REST routes as df-core registers them (routes/routes.php).
     *
     * @return Route[]
     */
    private static function apiRoutes()
    {
        $verbs = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        $servicePattern = '[_0-9a-zA-Z-.]+';
        $resourcePathPattern = '[0-9a-zA-ZÀ-ÿ-_@&\#\!=,:;\/\^\$\.\|\{\}\[\]\(\)\*\+\?\' ]+';

        return [
            (new Route($verbs, 'api/{version}/{service}/{resource?}', []))->where(
                ['version' => 'v[0-9.]+', 'service' => $servicePattern, 'resource' => $resourcePathPattern]
            ),
            (new Route($verbs, 'api/{service}/{resource?}', []))->where(
                ['service' => $servicePattern, 'resource' => $resourcePathPattern]
            ),
        ];
    }

    /**
     * A request for the raw (still encoded) path, bound to the API route the
     * router would match, if any.
     *
     * @param string      $path    path below api/v2/, as sent on the wire
     * @param string      $method
     * @param bool        $routed  bind the matching route (false: no route, path only)
     * @param bool        $matched set to whether a route matched
     * @param string|null $content request body
     * @param array       $server  server variables (e.g. CONTENT_TYPE)
     *
     * @return Request
     */
    private static function request($path, $method = 'GET', $routed = true, &$matched = null, $content = null, array $server = [])
    {
        $request = Request::create('http://localhost/api/v2/' . $path, $method, [], [], [], $server, $content);
        $matched = false;
        if ($routed) {
            foreach (static::apiRoutes() as $route) {
                if ($route->matches($request)) {
                    $route->bind($request);
                    $request->setRouteResolver(function () use ($route) {
                        return $route;
                    });
                    $matched = true;
                    break;
                }
            }
        }

        return $request;
    }

    public static function adminPaths()
    {
        $id = '42';

        return [
            'plain'                    => ['system/admin', 'system/admin'],
            'trailing slash'           => ['system/admin/', 'system/admin'],
            'resource case'            => ['system/Admin', 'system/Admin'],
            'service case'             => ['SYSTEM/admin', 'system/admin'],
            'double slash'             => ['system//admin', 'system/admin'],
            'dot segment'              => ['system/./admin', 'system/admin'],
            'parent segment'           => ['system/x/../admin', 'system/admin'],
            'encoded first letter'     => ['system/%61dmin', 'system/admin'],
            'encoded inner letter'     => ['system/adm%69n', 'system/admin'],
            'encoded upper case'       => ['system/%41DMIN', 'system/ADMIN'],
            'encoded slash'            => ['system%2Fadmin', 'system/admin'],
            'encoded slash lower'      => ['system%2fadmin', 'system/admin'],
            'double encoded'           => ['system/%2561dmin', 'system/admin'],
            'record'                   => ["system/admin/$id", "system/admin/$id"],
            'encoded record'           => ["system/%61dmin/$id", "system/admin/$id"],
            'record encoded slash'     => ["system/admin%2F$id", "system/admin/$id"],
            'record trailing slash'    => ["system/admin/$id/", "system/admin/$id"],
            'record upper resource'    => ["system/ADMIN/$id", "system/ADMIN/$id"],
            'own resource case'        => ['system/admin/Profile', 'system/admin/Profile'],
            'own before record'        => ["system/admin/session/../$id", "system/admin/$id"],
            'encoded own before record' => ["system/admin/session%2F..%2F$id", "system/admin/$id"],
        ];
    }

    /**
     * Paths the router dispatches to the system/admin resource itself.
     */
    public static function routedAdminPaths()
    {
        return [
            'own resource after empty' => ['system/admin//session', 'system/admin/session'],
            'record before own'        => ['system/admin/42/../session', 'system/admin/session'],
            'encoded record before own' => ['system/admin/42%2F..%2Fsession', 'system/admin/session'],
        ];
    }

    public static function ownAccountPaths()
    {
        return [
            'profile'                 => ['system/admin/profile', 'system/admin/profile'],
            'session'                 => ['system/admin/session', 'system/admin/session'],
            'password'                => ['system/admin/password', 'system/admin/password'],
            'encoded profile'         => ['system/admin/%70rofile', 'system/admin/profile'],
            'encoded session'         => ['system/admin/%73ession', 'system/admin/session'],
            'encoded password'        => ['system/admin/p%61ssword', 'system/admin/password'],
            'encoded resource'        => ['system/%61dmin/profile', 'system/admin/profile'],
            'encoded slash session'   => ['system/admin%2Fsession', 'system/admin/session'],
            'double encoded profile'  => ['system/admin/%2570rofile', 'system/admin/profile'],
            'session trailing slash'  => ['system/admin/session/', 'system/admin/session'],
            'service case session'    => ['SYSTEM/admin/session', 'system/admin/session'],
        ];
    }

    public static function otherPaths()
    {
        return [
            'role'                 => ['system/role', 'system/role'],
            'role record'          => ['system/role/7', 'system/role/7'],
            'similar resource'     => ['system/admins', 'system/admins'],
            'resource prefix'      => ['system/admin_report', 'system/admin_report'],
            'other service'        => ['files/system/admin', 'files/system/admin'],
            'table named admin'    => ['db/_table/admin', 'db/_table/admin'],
            'user session'         => ['user/session', 'user/session'],
            'environment'          => ['system/environment', 'system/environment'],
            'service root'         => ['system', 'system'],
        ];
    }

    #[DataProvider('adminPaths')]
    #[DataProvider('routedAdminPaths')]
    #[DataProvider('ownAccountPaths')]
    #[DataProvider('otherPaths')]
    public function testCanonicalPath($path, $canonical)
    {
        $this->assertSame($canonical, MiddlewareHelper::canonicalPath('/api/v2/' . $path));
        $this->assertSame($canonical, MiddlewareHelper::canonicalPath($path));
    }

    public function testCanonicalPathWithoutVersion()
    {
        $this->assertSame('system/admin/5', MiddlewareHelper::canonicalPath('/api/system/admin/5'));
        $this->assertSame('system/admin', MiddlewareHelper::canonicalPath('/api/v2.0/System/admin/'));
        $this->assertSame('', MiddlewareHelper::canonicalPath('/api/v2'));
        $this->assertSame('', MiddlewareHelper::canonicalPath('/api/v2/../../..'));
    }

    public function testDecodingStopsAfterThreeRounds()
    {
        $this->assertSame('admin', MiddlewareHelper::decodePath('%252561dmin'));
        $this->assertSame('%61dmin', MiddlewareHelper::decodePath('%25252561dmin'));
    }

    #[DataProvider('adminPaths')]
    public function testAdminResourcePaths($path)
    {
        foreach (['GET', 'POST', 'PATCH', 'DELETE'] as $method) {
            $routed = static::request($path, $method, true, $matched);
            if ($matched) {
                $this->assertTrue(HandleRestrictedAdmin::isAdminManagementRequest($routed), "$method $path (routed)");
            }
            $unrouted = static::request($path, $method, false);
            $this->assertTrue(HandleRestrictedAdmin::isAdminManagementRequest($unrouted), "$method $path (path only)");
        }
    }

    #[DataProvider('routedAdminPaths')]
    public function testRoutedAdminResourcePaths($path)
    {
        foreach (['GET', 'POST', 'PATCH', 'DELETE'] as $method) {
            $routed = static::request($path, $method, true, $matched);
            $this->assertTrue($matched, "$method $path is routed");
            $this->assertTrue(HandleRestrictedAdmin::isAdminManagementRequest($routed), "$method $path (routed)");
        }
    }

    #[DataProvider('ownAccountPaths')]
    public function testOwnAccountPaths($path)
    {
        foreach (['GET', 'POST', 'PATCH', 'DELETE'] as $method) {
            $routed = static::request($path, $method, true, $matched);
            if ($matched) {
                $this->assertFalse(HandleRestrictedAdmin::isAdminManagementRequest($routed), "$method $path (routed)");
            }
            $unrouted = static::request($path, $method, false);
            $this->assertFalse(HandleRestrictedAdmin::isAdminManagementRequest($unrouted), "$method $path (path only)");
        }
    }

    #[DataProvider('otherPaths')]
    public function testOtherPaths($path)
    {
        $this->assertFalse(HandleRestrictedAdmin::isAdminManagementRequest(static::request($path)));
        $this->assertFalse(HandleRestrictedAdmin::isAdminManagementRequest(static::request($path, 'GET', false)));
    }

    public function testRoutedTargetFollowsRouteParameters()
    {
        $request = static::request('SYSTEM/admin%2F42/', 'GET', true, $matched);
        $this->assertTrue($matched);
        $this->assertSame(['service' => 'system', 'resource' => ['admin', '42']],
            MiddlewareHelper::getRoutedTarget($request));

        // the REST controller drops ".." from the resource path before dispatching
        $request = static::request('system/x/../admin', 'GET', true, $matched);
        $this->assertTrue($matched);
        $this->assertSame(['service' => 'system', 'resource' => ['x', '', 'admin']],
            MiddlewareHelper::getRouteTarget($request));
        $this->assertSame(['service' => 'system', 'resource' => ['admin']],
            MiddlewareHelper::getPathTarget($request->getPathInfo()));
    }

    public function testRequestsTheRouterRejectsHaveNoRoute()
    {
        static::request('system/%2561dmin', 'GET', true, $matched);
        $this->assertFalse($matched);
    }

    public function testIsRoutedToMatchesWholeSegments()
    {
        $this->assertTrue(MiddlewareHelper::isRoutedTo(static::request('system/admin/session'), 'system', 'admin', 'session'));
        $this->assertTrue(MiddlewareHelper::isRoutedTo(static::request('User/session'), 'user', 'session'));
        $this->assertTrue(MiddlewareHelper::isRoutedTo(static::request('system/environment'), 'system', 'environment'));
        $this->assertTrue(MiddlewareHelper::isRoutedTo(static::request('system/role/3'), 'system', 'role'));

        $this->assertFalse(MiddlewareHelper::isRoutedTo(static::request('db/_table/sessions'), 'user', 'session'));
        $this->assertFalse(MiddlewareHelper::isRoutedTo(static::request('chat/session/4'), 'user', 'session'));
        $this->assertFalse(MiddlewareHelper::isRoutedTo(static::request('db/_table/environment'), 'system', 'environment'));
        $this->assertFalse(MiddlewareHelper::isRoutedTo(static::request('system/roles'), 'system', 'role'));
        $this->assertFalse(MiddlewareHelper::isRoutedTo(static::request('system/Role'), 'system', 'role'));
        // the routed target is what the endpoint served: system service root here
        $this->assertFalse(MiddlewareHelper::isRoutedTo(static::request('system//role'), 'system', 'role'));
    }

    public function testAddressesMatchesRoutedAndCanonicalTargets()
    {
        $this->assertTrue(MiddlewareHelper::addresses(static::request('system/service_report'), 'system', 'service_report'));
        $this->assertTrue(MiddlewareHelper::addresses(static::request('system/%73ervice_report'), 'system', 'service_report'));
        $this->assertTrue(MiddlewareHelper::addresses(static::request('system//role/3'), 'system', 'role'));
        $this->assertTrue(MiddlewareHelper::addresses(static::request('SYSTEM/Service'), 'system', 'service'));

        $this->assertFalse(MiddlewareHelper::addresses(static::request('system/service_report'), 'system', 'service'));
        $this->assertFalse(MiddlewareHelper::addresses(static::request('system/service_type'), 'system', 'service'));
        $this->assertFalse(MiddlewareHelper::addresses(static::request('files/system/role'), 'system', 'role'));
    }

    public function testNonListResourceParameter()
    {
        $next = function () {
            return new Response('ok');
        };

        foreach (['system/environment?resource=x', 'user/session?resource=x', 'user/session?is_restricted_admin=1&resource=1'] as $path) {
            $response = (new HandleRestrictedAdmin())->handle(static::request($path, 'POST'), $next);
            $this->assertSame('ok', $response->getContent(), $path);
        }
    }

    public function testEnvironmentResponseWithoutListContentIsLeftAlone()
    {
        $response = (new DoesRootAdminExist())->handle(static::request('system/environment?resource=x'), function () {
            return new Response('<html>text</html>');
        });

        $this->assertSame('<html>text</html>', $response->getContent());
    }

    public function testServicesOfSystemAndUserTypeUseTheTypeName()
    {
        static::serve(Request::create('http://localhost/'));

        $this->assertSame('system', MiddlewareHelper::serviceKey('sysconfig'));
        $this->assertSame('system', MiddlewareHelper::serviceKey('AppAdmin'));
        $this->assertSame('system', MiddlewareHelper::serviceKey('appadmin'));
        $this->assertSame('user', MiddlewareHelper::serviceKey('accounts'));
        $this->assertSame('db', MiddlewareHelper::serviceKey('DB'));
        $this->assertSame('unknown', MiddlewareHelper::serviceKey('unknown'));

        $this->assertSame(['service' => 'system', 'resource' => ['admin', '5']],
            MiddlewareHelper::getPathTarget('/api/v2/sysconfig/admin/5'));

        $this->assertTrue(HandleRestrictedAdmin::isAdminManagementRequest(static::request('sysconfig/admin/5', 'PATCH')));
        $this->assertTrue(HandleRestrictedAdmin::isAdminManagementRequest(static::request('appadmin/admin')));
        $this->assertFalse(HandleRestrictedAdmin::isAdminManagementRequest(static::request('sysconfig/admin/session')));
        $this->assertTrue(MiddlewareHelper::addresses(static::request('sysconfig/role/7', 'DELETE'), 'system', 'role'));
        $this->assertTrue(MiddlewareHelper::addresses(static::request('sysconfig/service_report'), 'system', 'service_report'));
        $this->assertTrue(MiddlewareHelper::isRoutedTo(static::request('sysconfig/service/3', 'PATCH'), 'system', 'service'));
        $this->assertTrue(MiddlewareHelper::isRoutedTo(static::request('accounts/session', 'POST'), 'user', 'session'));
        $this->assertFalse(HandleRestrictedAdmin::isAdminManagementRequest(static::request('db/_table/admin')));
        $this->assertFalse(MiddlewareHelper::addresses(static::request('files/role/7', 'DELETE'), 'system', 'role'));
    }

    public function testServiceNamesWithoutServiceList()
    {
        $this->assertSame('sysconfig', MiddlewareHelper::serviceKey('SysConfig'));
        $this->assertSame('system', MiddlewareHelper::serviceKey('SYSTEM'));
    }

    public static function adminOptionRequests()
    {
        return [
            'option true'          => ['system/user?admin=true', 'GET', null, true],
            'option one'           => ['system/user/5?admin=1', 'PATCH', ['first_name' => 'x'], true],
            'option list'          => ['system/user?admin[]=1', 'DELETE', null, true],
            'option in body'       => ['system/user', 'POST', ['admin' => true, 'resource' => [['email' => 'a@b.c']]], true],
            'encoded path'         => ['system/%75ser?admin=true', 'POST', ['resource' => [['email' => 'a@b.c']]], true],
            'typed service'        => ['sysconfig/user/5?admin=true', 'DELETE', null, true],
            'option false'         => ['system/user?admin=false', 'GET', null, false],
            'no option'            => ['system/user/5', 'PATCH', ['first_name' => 'x'], false],
            'other resource'       => ['system/app?admin=true', 'GET', null, false],
            'user service'         => ['user/profile?admin=true', 'GET', null, false],
        ];
    }

    #[DataProvider('adminOptionRequests')]
    public function testAdminOptionOfUsers($path, $method, $body, $expected)
    {
        $request = static::serveWithBody($path, $method, $body);

        $this->assertSame($expected, HandleRestrictedAdmin::isAdminManagementRequest($request));
    }

    public static function payloads()
    {
        $records = [['id' => 3, 'name' => 'a'], ['id' => '4']];

        return [
            'wrapped'                 => [['resource' => $records], 'application/json', $records, $records],
            'bare list'               => [$records, 'application/json', $records, $records],
            'single record'           => [['id' => 3, 'name' => 'a'], 'application/json', null, [['id' => 3, 'name' => 'a']]],
            'wrapped, octet-stream'   => [json_encode(['resource' => $records]), 'application/octet-stream', $records, $records],
            'bare list, octet-stream' => [json_encode($records), 'application/octet-stream', $records, $records],
            'bare list, no type'      => [json_encode($records), null, $records, $records],
            'wrapped record'          => [['resource' => ['id' => 3]], 'application/json', [3], [3]],
            'wrapper not a list'      => [['resource' => 'x'], 'application/json', [], []],
            'empty body'              => ['', 'application/json', null, []],
            'unreadable body'         => ['%%%', 'application/octet-stream', null, []],
            'plain text'              => ['[{"id":3}]', 'text/plain', null, []],
        ];
    }

    #[DataProvider('payloads')]
    public function testPayloadRecords($body, $contentType, $list, $records)
    {
        $request = static::serveWithBody('system/role', 'PATCH', $body, $contentType);
        $payload = MiddlewareHelper::getPayload($request);

        $this->assertSame($list, MiddlewareHelper::getRecordList($payload));
        $this->assertSame($records, MiddlewareHelper::getRecords($payload));
    }

    public function testFormPayload()
    {
        $request = static::serve(Request::create('http://localhost/api/v2/system/role', 'PATCH',
            ['resource' => [['id' => '3'], ['id' => '4']]], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']));

        $this->assertSame(['3', '4'], MiddlewareHelper::getRecordIds(MiddlewareHelper::getPayload($request)));
    }

    public function testPayloadOfAnotherRequest()
    {
        static::serve(Request::create('http://localhost/'));
        $other = Request::create('http://localhost/api/v2/system/role', 'PATCH', [], [], [],
            ['CONTENT_TYPE' => 'application/octet-stream'], json_encode([['id' => 3]]));

        $this->assertSame(['3'], MiddlewareHelper::getRecordIds(MiddlewareHelper::getPayload($other)));
    }

    public function testIdLists()
    {
        $this->assertSame(['1', '2', '3'], MiddlewareHelper::getIdList('1, 2,,3'));
        $this->assertSame(['1', '2'], MiddlewareHelper::getIdList(['1', 2, ['x'], 2]));
        $this->assertSame(['5'], MiddlewareHelper::getIdList(5));
        $this->assertSame([], MiddlewareHelper::getIdList(null));
        $this->assertSame([], MiddlewareHelper::getIdList(''));
        $this->assertSame(['3', '4', '5'], MiddlewareHelper::getRecordIds(['resource' => [['id' => 3], 4, ['id' => '5'], ['name' => 'x'], ['id' => ['y']]]]));
    }

    public function testOptionValues()
    {
        foreach (['true', '1', 'yes', 'on', true, 1, ['1']] as $value) {
            $this->assertTrue(MiddlewareHelper::isTrue($value), var_export($value, true));
        }
        foreach (['false', '0', 'no', '', false, 0, null, []] as $value) {
            $this->assertFalse(MiddlewareHelper::isTrue($value), var_export($value, true));
        }
    }

    public static function roleRequests()
    {
        $wrapped = ['resource' => [['id' => 5, 'description' => 'd'], ['id' => '6']]];

        return [
            'record'                  => ['system/role/7', 'PATCH', ['description' => 'd'], 'application/json', ['7']],
            'encoded resource'        => ['system/r%6fle/7', 'PATCH', ['description' => 'd'], 'application/json', ['7']],
            'encoded slashes'         => ['system%2Frole%2F7', 'DELETE', null, 'application/json', ['7']],
            'service case'            => ['SYSTEM/role/7/', 'DELETE', null, 'application/json', ['7']],
            'typed service'           => ['sysconfig/role/7', 'DELETE', null, 'application/json', ['7']],
            'ids option'              => ['system/role?ids=1,2', 'DELETE', null, 'application/json', ['1', '2']],
            'ids option list'         => ['system/role?ids[]=1&ids[]=2', 'PATCH', $wrapped, 'application/json', ['1', '2', '5', '6']],
            'ids in body'             => ['system/role', 'PATCH', ['ids' => '8,9', 'resource' => [['description' => 'd']]], 'application/json', ['8', '9']],
            'wrapped records'         => ['system/role', 'PATCH', $wrapped, 'application/json', ['5', '6']],
            'bare list'               => ['system/role', 'PUT', [['id' => 5, 'description' => 'd']], 'application/json', ['5']],
            'bare list delete'        => ['system/role', 'DELETE', [['id' => 5]], 'application/json', ['5']],
            'single record'           => ['system/role', 'PATCH', ['id' => 5, 'description' => 'd'], 'application/json', ['5']],
            'octet-stream'            => ['system/role', 'PATCH', json_encode($wrapped), 'application/octet-stream', ['5', '6']],
            'bare list octet-stream'  => ['system/role', 'DELETE', json_encode([['id' => 5]]), 'application/octet-stream', ['5']],
            'nothing named'           => ['system/role?filter=name%3Dx', 'DELETE', null, 'application/json', []],
        ];
    }

    #[DataProvider('roleRequests')]
    public function testRequestedRoleIds($path, $method, $body, $contentType, $expected)
    {
        $request = static::serveWithBody($path, $method, $body, $contentType);
        $ids = HandleRestrictedAdminRole::getRequestedRoleIds($request, MiddlewareHelper::getPayload($request));
        sort($ids);

        $this->assertSame($expected, $ids);
    }

    public static function serviceAuditRequests()
    {
        $records = [['id' => 3, 'description' => 'd'], ['id' => 4]];

        return [
            'record'               => ['system/service/12', 'PATCH', ['description' => 'd'], 'application/json', ['service', '12'], ['12']],
            'encoded slash'        => ['system%2Fservice%2F12', 'DELETE', null, 'application/json', ['service', '12'], ['12']],
            'typed service'        => ['sysconfig/service/12', 'DELETE', null, 'application/json', ['service', '12'], ['12']],
            'ids option'           => ['system/service?ids=3,4', 'PATCH', ['resource' => [['description' => 'd']]], 'application/json', ['service'], ['3', '4']],
            'ids in body'          => ['system/service', 'PATCH', ['ids' => [322], 'resource' => $records], 'application/json', ['service'], $records],
            'wrapped records'      => ['system/service', 'PATCH', ['resource' => $records], 'application/json', ['service'], $records],
            'bare list'            => ['system/service', 'PATCH', $records, 'application/json', ['service'], $records],
            'octet-stream'         => ['system/service', 'DELETE', json_encode(['resource' => $records]), 'application/octet-stream', ['service'], $records],
            'create'               => ['system/service?ids=9', 'POST', [['name' => 'n1']], 'application/json', ['service'], [['name' => 'n1']]],
            'resource name case'   => ['system/Service/12', 'PATCH', ['description' => 'd'], 'application/json', [], []],
            'other service'        => ['db/_table/x/../../../system/service/12', 'PATCH', ['description' => 'd'], 'application/json', [], []],
        ];
    }

    #[DataProvider('serviceAuditRequests')]
    public function testAuditedServices($path, $method, $body, $contentType, $segments, $services)
    {
        $audit = (new AuditProbe())->prepare(static::serveWithBody($path, $method, $body, $contentType));

        $this->assertSame($segments, $audit->segments());
        $this->assertSame($services, $segments ? $audit->affected() : []);
    }

    public function testAuditServiceIds()
    {
        $audit = new AuditProbe();

        $this->assertSame('322', $audit->serviceId(322));
        $this->assertSame('322', $audit->serviceId('322'));
        $this->assertSame(5, $audit->serviceId(['id' => 5]));
        $this->assertNull($audit->serviceId(['name' => 'n1']));
    }

    public function testDeprecatedEndpointCheck()
    {
        $this->assertTrue(MiddlewareHelper::requestUrlContains(static::request('system/role/3'), 'system/role'));
        $this->assertTrue(MiddlewareHelper::requestUrlContains(static::request('system/service_report'), 'system/service_report'));
        $this->assertFalse(MiddlewareHelper::requestUrlContains(static::request('system/service_report'), 'system/service'));
        $this->assertFalse(MiddlewareHelper::requestUrlContains(static::request('system/role'), ''));
    }
}

/**
 * Exposes how ServiceLevelAudit picks the services a request works on.
 */
class AuditProbe extends ServiceLevelAudit
{
    public function prepare($request)
    {
        $this->request = $request;
        $this->method = $request->getMethod();
        $this->payload = MiddlewareHelper::getPayload($request);
        $this->resource = $this->getServiceResourceSegments();

        return $this;
    }

    public function segments()
    {
        return $this->resource;
    }

    public function affected()
    {
        return $this->getAffectedServices();
    }

    public function serviceId($serviceData)
    {
        return $this->getServiceId($serviceData);
    }
}
