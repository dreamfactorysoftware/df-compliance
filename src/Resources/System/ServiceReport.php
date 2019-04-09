<?php

namespace DreamFactory\Core\Compliance\Resources\System;

use DreamFactory\Core\Compliance\Models\ServiceReport as ServiceReportModel;
use DreamFactory\Core\Exceptions\NotImplementedException;
use DreamFactory\Core\System\Resources\BaseSystemResource;

class ServiceReport extends BaseSystemResource
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

    /**
     * {@inheritdoc}
     */
    protected function handlePOST()
    {
        throw new NotImplementedException('The verb "' . $this->request->getMethod() . '" is not supported.');
    }

    /**
     * {@inheritdoc}
     */
    protected function handlePATCH()
    {
        throw new NotImplementedException('The verb "' . $this->request->getMethod() . '" is not supported.');
    }
}