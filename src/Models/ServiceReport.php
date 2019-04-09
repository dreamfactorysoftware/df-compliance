<?php
namespace DreamFactory\Core\Compliance\Models;

use DreamFactory\Core\Events\RoleDeletedEvent;
use DreamFactory\Core\Events\RoleModifiedEvent;
use DreamFactory\Core\Models\BaseSystemModel;
use DreamFactory\Core\Events\ServiceReportDeletedEvent;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Utility\JWTUtilities;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;

/**
 * Service report
 *
 * @property integer $id
 * @property string  service_name
 * @property string  user_email
 * @property string  action
 * @property string  request_verb
 * @property string  $created_date
 * @property string  $last_modified_date
 * @method static Builder|ServiceReport whereId($value)
 * @method static Builder|ServiceReport whereServiceName($value)
 * @method static Builder|ServiceReport whereUserEmail($value)
 * @method static Builder|ServiceReport whereAction($value)
 * @method static Builder|ServiceReport whereRequestVerb($value)
 * @method static Builder|ServiceReport whereCreatedDate($value)
 * @method static Builder|ServiceReport whereLastModifiedDate($value)
 */
class ServiceReport extends BaseSystemModel
{
    protected $table = 'service_report';

    protected $fillable = [
        'id',
        'service_name',
        'user_email',
        'action',
        'request_verb',
    ];

    protected $casts = ['id' => 'integer'];
}