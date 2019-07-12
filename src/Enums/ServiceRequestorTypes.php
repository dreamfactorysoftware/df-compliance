<?php
namespace DreamFactory\Core\Compliance\Enums;

use DreamFactory\Core\Enums\ServiceRequestorTypes as CoreServiceRequestorTypes;


/**
 * Various service requestor types as bitmask-able values
 */
class ServiceRequestorTypes extends CoreServiceRequestorTypes
{
    /**
     * @return int
     */
    public static function getAllRequestorTypes()
    {
        return
            self::API |
            self::SCRIPT;
    }
}
