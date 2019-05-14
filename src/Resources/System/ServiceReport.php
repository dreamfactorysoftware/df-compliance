<?php

namespace DreamFactory\Core\Compliance\Resources\System;

use DreamFactory\Core\Compliance\Models\ServiceReport as ServiceReportModel;
use DreamFactory\Core\Exceptions\NotImplementedException;
use DreamFactory\Core\System\Resources\ReadOnlySystemResource;

class ServiceReport extends ReadOnlySystemResource
{
    /**
     * @var string DreamFactory\Core\Models\BaseSystemModel Model Class name.
     */
    protected static $model = ServiceReportModel::class;

    protected $allowedVerbs = [
        'GET',
        'DELETE'
    ];

    public function __construct($settings = [])
    {
        parent::__construct($settings);

        $this->serviceReportModel = new static::$model;
    }
}