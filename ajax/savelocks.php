<?php

/**
 * Store the locks picked for one rule.
 *
 * The screen posts the rule's complete lock set, not a diff: the picker keeps
 * every itemtype's picks in memory while the admin moves between forms, so the
 * only thing that can express a removal is a wholesale replace.
 *
 * On GLPI 11 CSRF is enforced upstream by the kernel listener, which reads the
 * `X-Glpi-Csrf-Token` header on XHR requests. GLPI 10 leaves it to the script,
 * so the check happens here and a fresh token is handed back, because GLPI 10
 * burns the token on use.
 */

// GLPI 10 executes plugin scripts directly and expects them to bootstrap the
// core themselves. GLPI 11 boots its kernel before requiring the file, where
// including this again would be an error.
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

Session::checkRight('config', UPDATE);
PluginFieldguardCompat::checkAjaxCsrf();

header('Content-Type: application/json; charset=UTF-8');

$rules_id = (int) ($_POST['rules_id'] ?? 0);
$locks    = (array) ($_POST['locks'] ?? []);

$rule = new PluginFieldguardRule();
if ($rules_id <= 0 || !$rule->getFromDB($rules_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'unknown rule']);
    return;
}

$count = PluginFieldguardLock::setLocksForRule($rules_id, $locks);

echo json_encode([
    'success'    => true,
    'count'      => $count,
    // Cast so an empty set serialises as `{}` and not `[]`.
    'locks'      => (object) PluginFieldguardLock::getLocksForRule($rules_id),
    'csrf_token' => Session::getNewCSRFToken(),
]);
