<?php

namespace DreamFactory\Core\Compliance\Utility;

use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Utility\ResourcesWrapper;
use DreamFactory\Core\Utility\ResponseFactory;
use DreamFactory\Core\Utility\ServiceRequest;
use Illuminate\Container\Container;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;

/**
 * Works out which API endpoint a request is for, and reads its ids and records,
 * the same way the DreamFactory REST controller and system resources do.
 *
 * A "target" is ['service' => string, 'resource' => string[]], e.g.
 * api/v2/system/admin/5 -> ['service' => 'system', 'resource' => ['admin', '5']].
 * Services of the built-in system and user types are named by their type.
 */
class MiddlewareHelper
{
    /**
     * Maximum number of percent-decoding passes applied to a request path.
     */
    const MAX_DECODE_ROUNDS = 3;

    /**
     * Service types whose services appear in targets under the type name.
     */
    const TYPED_SERVICES = ['system', 'user'];

    /**
     * Resolved targets per request object.
     *
     * @var \WeakMap|null
     */
    private static $targetCache = null;

    /**
     * The target the request is dispatched to: the matched route's service and
     * resource parameters, or the canonical request path when no API route
     * with a service parameter has been matched.
     *
     * Use it for features that act on what the endpoint served (e.g. adding
     * fields to its response).
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array|null
     */
    public static function getRoutedTarget($request)
    {
        $targets = static::getTargets($request);

        return $targets['route'] ?? $targets['path'];
    }

    /**
     * Targets from both the matched route parameters and the canonical request
     * path, the routed target first.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array[]
     */
    public static function getRequestTargets($request)
    {
        $targets = static::getTargets($request);

        return array_values(array_filter([$targets['route'], $targets['path']]));
    }

    /**
     * Is the request dispatched to the given service and resource path,
     * e.g. isRoutedTo($request, 'system', 'admin', 'session').
     *
     * The service name is compared case-insensitively (as DreamFactory resolves
     * services), resource segments exactly (as DreamFactory resolves resources).
     *
     * @param \Illuminate\Http\Request $request
     * @param string                   $service
     * @param string                   ...$resource
     *
     * @return bool
     */
    public static function isRoutedTo($request, $service, ...$resource)
    {
        return static::targetMatches(static::getRoutedTarget($request), $service, $resource, true);
    }

    /**
     * Does the routed or the canonical target of the request start with the
     * given service and resource path. Names are compared case-insensitively.
     *
     * @param \Illuminate\Http\Request $request
     * @param string                   $service
     * @param string                   ...$resource
     *
     * @return bool
     */
    public static function addresses($request, $service, ...$resource)
    {
        foreach (static::getRequestTargets($request) as $target) {
            if (static::targetMatches($target, $service, $resource)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the request address the given endpoint path, e.g. 'system/role'.
     *
     * @deprecated Use addresses() or isRoutedTo().
     *
     * @param \Illuminate\Http\Request $request
     * @param string                   $endpoint
     *
     * @return bool
     */
    public static function requestUrlContains($request, $endpoint)
    {
        $segments = array_values(array_filter(explode('/', (string)$endpoint), 'strlen'));

        return !empty($segments) && static::addresses($request, ...$segments);
    }

    /**
     * Does a target start with the given service and resource segments.
     *
     * @param array|null $target
     * @param string     $service
     * @param string[]   $resource
     * @param bool       $exactResource compare resource segments case-sensitively
     *
     * @return bool
     */
    public static function targetMatches($target, $service, array $resource = [], $exactResource = false)
    {
        if (!is_array($target) || ($target['service'] ?? null) !== strtolower($service)) {
            return false;
        }

        $segments = $target['resource'] ?? [];
        foreach (array_values($resource) as $index => $name) {
            if (!isset($segments[$index])) {
                return false;
            }
            $matches = $exactResource ? ($segments[$index] === $name) : (0 === strcasecmp($segments[$index], $name));
            if (!$matches) {
                return false;
            }
        }

        return true;
    }

    /**
     * Target taken from the matched route parameters, prepared the way the
     * REST controller and handlers prepare them before dispatching.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array|null
     */
    public static function getRouteTarget($request)
    {
        $route = method_exists($request, 'route') ? $request->route() : null;
        if (!$route instanceof Route || !$route->hasParameters()) {
            return null;
        }

        $service = $route->parameter('service');
        if (!is_string($service) || '' === $service) {
            return null;
        }

        $resource = $route->parameter('resource');
        $resource = is_string($resource) ? rtrim(str_replace('..', '', $resource), '/') : '';

        return [
            'service'  => static::serviceKey($service),
            'resource' => ('' === $resource) ? [] : explode('/', $resource),
        ];
    }

    /**
     * Target taken from a request path after canonicalization.
     *
     * @param string $path
     *
     * @return array|null
     */
    public static function getPathTarget($path)
    {
        $canonical = static::canonicalPath((string)$path);
        if ('' === $canonical) {
            return null;
        }

        $segments = explode('/', $canonical);
        $service = static::serviceKey(array_shift($segments));

        return ['service' => $service, 'resource' => $segments];
    }

    /**
     * Canonical form of a request path: percent-decoded until stable, empty and
     * dot segments resolved, relative to the API root (prefix and version
     * removed) and with the service name lower-cased.
     *
     * e.g. /api/v2/SYSTEM//x/../%61dmin/ -> system/admin
     *
     * @param string $path
     *
     * @return string
     */
    public static function canonicalPath($path)
    {
        $path = static::decodePath((string)$path);

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        $prefix = static::getApiPrefixSegments();
        if (!empty($prefix) && count($segments) >= count($prefix) &&
            array_map('strtolower', array_slice($segments, 0, count($prefix))) === $prefix
        ) {
            $segments = array_slice($segments, count($prefix));
        }

        if (isset($segments[0]) && preg_match('/^v[0-9.]+$/i', $segments[0])) {
            array_shift($segments);
        }

        if (isset($segments[0])) {
            $segments[0] = strtolower($segments[0]);
        }

        return implode('/', $segments);
    }

    /**
     * Percent-decode a path until it no longer changes, at most MAX_DECODE_ROUNDS times.
     *
     * @param string $path
     *
     * @return string
     */
    public static function decodePath($path)
    {
        $path = (string)$path;
        for ($round = 0; $round < static::MAX_DECODE_ROUNDS; $round++) {
            $decoded = rawurldecode($path);
            if ($decoded === $path) {
                break;
            }
            $path = $decoded;
        }

        return $path;
    }

    /**
     * Name of a service as it appears in targets: the lower-cased service name,
     * or the type name for services of the built-in system and user types.
     *
     * @param string $service
     *
     * @return string
     */
    public static function serviceKey($service)
    {
        $service = strtolower((string)$service);
        if ('' === $service || in_array($service, static::TYPED_SERVICES, true)) {
            return $service;
        }

        $type = static::getServiceType($service);

        return in_array($type, static::TYPED_SERVICES, true) ? $type : $service;
    }

    /**
     * The request body, read the way the REST API reads it (JSON, XML, CSV or
     * form data, whatever the declared content type). Empty when the body is
     * missing or is not a set of values.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public static function getPayload($request)
    {
        try {
            if (static::isCurrentRequest($request)) {
                $payload = (new ServiceRequest())->getPayloadData();
            } else {
                // A request that is not the one being served
                // (e.g. built by a test). Its content may be unset or not a string.
                $content = $request->getContent();
                if (!is_string($content) || '' === $content) {
                    $payload = $request->input();
                } else {
                    $isJson = $request->isJson() || null !== json_decode($content);
                    $payload = $isJson ? $request->json()->all() : $request->input();
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * The records of a payload when it holds a list: the records under the
     * resources wrapper, or the payload itself when it is a bare list.
     * Null when the payload is a single record.
     *
     * @param array $payload
     *
     * @return array|null
     */
    public static function getRecordList(array $payload)
    {
        $wrapper = static::getResourcesWrapper();
        if (!isset($payload[0]) && !Arr::has($payload, $wrapper)) {
            return null;
        }

        $records = Arr::get($payload, $wrapper, isset($payload[0]) ? $payload : []);

        return is_array($records) ? array_values($records) : [];
    }

    /**
     * The records of a payload: its list of records, or the payload itself
     * as the only record.
     *
     * @param array $payload
     *
     * @return array
     */
    public static function getRecords(array $payload)
    {
        if (empty($payload)) {
            return [];
        }

        return static::getRecordList($payload) ?? [$payload];
    }

    /**
     * Ids named by the records of a payload: the "id" of each record, or the
     * record itself when it is a plain value.
     *
     * @param array $payload
     *
     * @return string[]
     */
    public static function getRecordIds(array $payload)
    {
        $ids = [];
        foreach (static::getRecords($payload) as $record) {
            $id = is_array($record) ? ($record['id'] ?? null) : $record;
            if (is_scalar($id) && '' !== (string)$id) {
                $ids[] = (string)$id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Ids given as an "ids" option: a comma-separated string or a list.
     *
     * @param mixed $ids
     *
     * @return string[]
     */
    public static function getIdList($ids)
    {
        if (is_array($ids)) {
            $ids = array_filter($ids, 'is_scalar');
        } elseif (is_scalar($ids)) {
            $ids = explode(',', (string)$ids);
        } else {
            return [];
        }

        $ids = array_filter(array_map('trim', array_map('strval', $ids)), 'strlen');

        return array_values(array_unique($ids));
    }

    /**
     * Is an option value set to true. A list counts as set.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public static function isTrue($value)
    {
        if (is_array($value)) {
            return !empty($value);
        }

        return is_scalar($value) && to_bool($value);
    }

    /**
     * Ids of the records the request's filter selects, resolved by the given
     * system resource the same way it resolves them for updates and deletes.
     *
     * @param string $resourceClass e.g. \DreamFactory\Core\System\Resources\Role::class
     *
     * @return array|null null when the filter cannot be resolved
     */
    public static function getIdsFromFilter($resourceClass)
    {
        try {
            $resource = new $resourceClass();
            $ids = (function ($request) {
                $this->request = $request;

                return $this->getIdsFromFilter();
            })->call($resource, new ServiceRequest());
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($ids) ? array_values(array_map('strval', $ids)) : null;
    }

    /**
     * A DreamFactory JSON error response with status 403.
     *
     * @param string $message
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public static function forbiddenResponse($message)
    {
        return ResponseFactory::sendException(new ForbiddenException($message));
    }

    /**
     * A DreamFactory JSON error response for an error raised while handling a request.
     *
     * @param \Throwable $error
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public static function errorResponse(\Throwable $error)
    {
        if (!$error instanceof \Exception) {
            $error = new InternalServerErrorException($error->getMessage());
        }

        return ResponseFactory::sendException($error);
    }

    /**
     * Routed and canonical-path targets of a request, resolved once per request.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array ['route' => array|null, 'path' => array|null]
     */
    protected static function getTargets($request)
    {
        $route = method_exists($request, 'route') ? $request->route() : null;
        $path = static::getRequestPath($request);
        if (!is_object($request)) {
            return ['route' => null, 'path' => static::getPathTarget($path)];
        }

        self::$targetCache ??= new \WeakMap();
        $cached = self::$targetCache[$request] ?? null;
        if (is_array($cached) && $cached['for'] === [$route, $path]) {
            return $cached;
        }

        return self::$targetCache[$request] = [
            'for'   => [$route, $path],
            'route' => static::getRouteTarget($request),
            'path'  => static::getPathTarget($path),
        ];
    }

    /**
     * Is this the request the application is serving
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    protected static function isCurrentRequest($request)
    {
        try {
            $container = Container::getInstance();

            return $container->bound('request') && $container->make('request') === $request;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Type of the named service, if it exists. Service names are matched
     * case-insensitively, like the REST controller does.
     *
     * @param string $service lower-cased service name
     *
     * @return string|null
     */
    protected static function getServiceType($service)
    {
        try {
            $container = Container::getInstance();
            if (!$container->bound('df.service')) {
                return null;
            }
            $types = $container->make('df.service')->getServiceNameTypeMap();
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($types)) {
            return null;
        }
        if (isset($types[$service])) {
            return $types[$service];
        }
        foreach ($types as $name => $type) {
            if (0 === strcasecmp((string)$name, $service)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * The configured resources wrapper (default "resource").
     *
     * @return string
     */
    protected static function getResourcesWrapper()
    {
        try {
            $wrapper = ResourcesWrapper::getWrapper();
        } catch (\Throwable $e) {
            $wrapper = ResourcesWrapper::DEFAULT_WRAPPER;
        }

        return is_string($wrapper) ? $wrapper : ResourcesWrapper::DEFAULT_WRAPPER;
    }

    /**
     * Raw (not decoded) path of the request.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return string
     */
    protected static function getRequestPath($request)
    {
        return method_exists($request, 'getPathInfo') ? (string)$request->getPathInfo() : '';
    }

    /**
     * Lower-cased segments of the configured API route prefix (default "api").
     *
     * @return string[]
     */
    protected static function getApiPrefixSegments()
    {
        $prefix = 'api';
        $container = Container::getInstance();
        if ($container->bound('config')) {
            $prefix = (string)$container->make('config')->get('df.api_route_prefix', 'api');
        }
        $prefix = strtolower(trim($prefix, '/'));

        return ('' === $prefix) ? [] : explode('/', $prefix);
    }
}
