<?php

function plugin_fieldguard_install()
{
    $migration = new Migration(PLUGIN_FIELDGUARD_VERSION);

    // Rules first: the lock table's upgrade path turns existing profile-scoped
    // locks into rules, so their tables have to be there already.
    PluginFieldguardRule::install($migration);
    PluginFieldguardLock::install($migration);

    $migration->executeMigration();

    return true;
}

function plugin_fieldguard_uninstall()
{
    PluginFieldguardLock::uninstall();
    PluginFieldguardRule::uninstall();

    return true;
}

/**
 * Drop locked fields from the input before GLPI creates the item.
 */
function plugin_fieldguard_pre_item_add(CommonDBTM $item)
{
    PluginFieldguardLock::filterInput($item);
}

/**
 * Drop locked fields from the input before GLPI updates the item.
 */
function plugin_fieldguard_pre_item_update(CommonDBTM $item)
{
    PluginFieldguardLock::filterInput($item);
}
