<?php

namespace DreamFactory\Core\Compliance\Utility;

use Illuminate\Support\Str;


class MiddlewareHelper
{
    /**
     * Is request URL contains endpoint
     *
     * @param $request
     * @param $endpoint
     * @return bool
     */
    public static function requestUrlContains($request, $endpoint)
    {
        return Str::contains($request->url(), $endpoint);
    }
}