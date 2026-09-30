<?php

/**
 * Field Guard - lock GLPI form fields per profile.
 */

define('PLUGIN_FIELDGUARD_VERSION', '1.3.0');
define('PLUGIN_FIELDGUARD_MIN_GLPI', '10.0.0');
define('PLUGIN_FIELDGUARD_MAX_GLPI', '11.9.99');

/**
 * Plugin bootstrap: register hooks.
 */
function plugin_init_fieldguard()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['fieldguard'] = true;
    $PLUGIN_HOOKS['config_page']['fieldguard']    = 'front/config.form.php';

    Plugin::registerClass('PluginFieldguardRule');
    Plugin::registerClass('PluginFieldguardLock');

    // Front-end enforcement: shipped on every authenticated page.
    // The path differs between GLPI 10 and 11; see PluginFieldguardCompat.
    $PLUGIN_HOOKS['add_css']['fieldguard']        = [PluginFieldguardCompat::assetPath('css/fieldguard.css')];
    $PLUGIN_HOOKS['add_javascript']['fieldguard'] = [PluginFieldguardCompat::assetPath('js/fieldguard.js')];

    // Ship the session's locks inline in <head> rather than fetching them over
    // AJAX, so fields are already greyed out on first paint.
    $payload = plugin_fieldguard_get_locks_payload();
    if ($payload !== null) {
        $PLUGIN_HOOKS['add_header_tag']['fieldguard'] = [
            [
                'tag'        => 'meta',
                'properties' => [
                    'name'    => 'fieldguard:locks',
                    'content' => $payload,
                ],
            ],
        ];

        // Only for forms whose action does not name their itemtype, and only
        // for the ones this session holds locks on.
        $resolvers = PluginFieldguardLock::getFormResolvers(
            array_keys(PluginFieldguardLock::getLocksForCurrentSession())
        );
        if ($resolvers !== []) {
            $PLUGIN_HOOKS['add_header_tag']['fieldguard'][] = [
                'tag'        => 'meta',
                'properties' => [
                    'name'    => 'fieldguard:resolvers',
                    'content' => json_encode($resolvers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ];
        }
    }

    // Server-side enforcement. `Plugin::doHook()` dispatches item hooks by exact
    // class name (no wildcard), so every itemtype that could be locked must be
    // registered.
    foreach (PluginFieldguardLock::getEnforcedItemtypes() as $itemtype) {
        $PLUGIN_HOOKS['pre_item_add']['fieldguard'][$itemtype]    = 'plugin_fieldguard_pre_item_add';
        $PLUGIN_HOOKS['pre_item_update']['fieldguard'][$itemtype] = 'plugin_fieldguard_pre_item_update';
    }
}

function plugin_version_fieldguard()
{
    return [
        // Deliberately not translated: GLPI persists this into
        // `glpi_plugins.name` at install time, so a translated value would
        // freeze whatever language the installing admin happened to use and
        // show it to everyone. The configuration page translates its own title.
        'name'         => 'Field Guard',
        'version'      => PLUGIN_FIELDGUARD_VERSION,
        'author'       => 'Ampris',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_FIELDGUARD_MIN_GLPI,
                'max' => PLUGIN_FIELDGUARD_MAX_GLPI,
            ],
            'php' => ['min' => '7.4'],
        ],
    ];
}

function plugin_fieldguard_check_prerequisites()
{
    return true;
}

function plugin_fieldguard_check_config($verbose = false)
{
    return true;
}

/**
 * JSON blob of the locks that apply to the current session.
 *
 * Returns null when there is nothing to enforce (no session, plugin not installed
 * yet, or no rule matching the session) so that no empty meta tag is emitted.
 */
function plugin_fieldguard_get_locks_payload(): ?string
{
    global $DB;

    if (!isset($_SESSION['glpiactiveprofile']['id'])) {
        return null;
    }
    if (!($DB instanceof DBmysql) || !$DB->connected) {
        return null;
    }
    if (
        !$DB->tableExists(PluginFieldguardLock::getTable())
        || !$DB->tableExists(PluginFieldguardRule::getTable())
    ) {
        return null;
    }

    $locks = PluginFieldguardLock::getLocksForCurrentSession();
    if ($locks === []) {
        return null;
    }

    return json_encode($locks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
