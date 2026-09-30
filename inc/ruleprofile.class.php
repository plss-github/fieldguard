<?php

/**
 * Link row: this rule applies to this profile.
 *
 * A rule with no row here applies to every profile.
 */
class PluginFieldguardRuleProfile extends CommonDBTM
{
    public static $rightname = 'config';

    public $dohistory = false;

    public static function getTypeName($nb = 0)
    {
        return _n('Profile', 'Profiles', $nb);
    }
}
