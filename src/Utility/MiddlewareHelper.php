<?php

namespace DreamFactory\Core\Compliance\Utility;

use Illuminate\Support\Str;


class MiddlewareHelper
{
    /**
     * Is licence is Gold
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