<?php

namespace DreamFactory\Core\Compliance\Utility;

use DreamFactory\Core\Utility\Environment;
use DreamFactory\Core\Enums\LicenseLevel;

class LicenseCheck
{
    /**
     * Is licence is Gold
     *
     * @return bool
     */
    public static function isGoldLicense()
    {
        return Environment::getLicenseLevel() === LicenseLevel::GOLD;
    }
}