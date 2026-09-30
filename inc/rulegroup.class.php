<?php

/**
 * Link row: this rule applies to this group.
 *
 * A rule with no row here applies to every group.
 */
class PluginFieldguardRuleGroup extends CommonDBTM
{
    public static $rightname = 'config';

    public $dohistory = false;

    public static function getTypeName($nb = 0)
    {
        return _n('Group', 'Groups', $nb);
    }
}
