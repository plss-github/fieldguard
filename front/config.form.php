<?php

/**
 * Field Guard - rule list.
 *
 * Rules are the unit of configuration: each one names the profiles and groups it
 * applies to and owns the locks picked for it. Editing happens in rule.form.php.
 */

// GLPI 10 executes plugin scripts directly and expects them to bootstrap the
// core themselves. GLPI 11 boots its kernel before requiring the file, where
// including this again would be an error.
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

// GLPI 11 requires legacy scripts from inside a controller method, so the
// globals have to be imported explicitly.
global $CFG_GLPI, $DB;

Session::checkRight('config', UPDATE);

$rule     = new PluginFieldguardRule();
$self_url = PluginFieldguardCompat::scriptUrl('front/config.form.php');
$rule_url = PluginFieldguardCompat::scriptUrl('front/rule.form.php');

if (isset($_POST['add'])) {
    $rules_id = (int) $rule->add([
        'name'      => (string) ($_POST['name'] ?? ''),
        'is_active' => 1,
    ]);

    Html::redirect($rules_id > 0 ? $rule_url . '?id=' . $rules_id : $self_url);
}

Html::header(
    __('Field Guard', 'fieldguard'),
    $self_url,
    'config',
    'plugins'
);

echo Html::css(
    PluginFieldguardCompat::assetUrl('css/picker.css'),
    ['version' => PluginFieldguardCompat::assetVersion('css/picker.css')]
);

/** Comma separated names of the dropdown rows a rule is restricted to. */
function plugin_fieldguard_audience_label(array $ids, string $table, string $any): string
{
    if ($ids === []) {
        return $any;
    }

    $names = [];
    foreach ($ids as $id) {
        $names[] = Dropdown::getDropdownName($table, $id);
    }
    sort($names);

    return implode(', ', $names);
}

$rules = $rule->find([], ['name']);
?>

<div class="fieldguard-config">
  <div class="row">
    <div class="col-12 col-xl-8">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title"><?php echo PluginFieldguardCompat::escape(PluginFieldguardRule::getTypeName(2)); ?></h3>
        </div>
        <div class="table-responsive">
          <table class="table card-table table-vcenter table-hover">
            <thead>
              <tr>
                <th><?php echo __('Name'); ?></th>
                <th><?php echo _n('Profile', 'Profiles', 2); ?></th>
                <th><?php echo _n('Group', 'Groups', 2); ?></th>
                <th class="text-center"><?php echo __('Locked fields', 'fieldguard'); ?></th>
                <th class="text-center"><?php echo __('Active'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if ($rules === []) { ?>
                <tr>
                  <td colspan="5" class="text-center text-muted py-4">
                    <?php echo __('No rule yet. Create the first one on the right.', 'fieldguard'); ?>
                  </td>
                </tr>
              <?php } ?>
              <?php foreach ($rules as $row) {
                    $rules_id = (int) $row['id']; ?>
                <tr>
                  <td>
                    <a href="<?php echo PluginFieldguardCompat::escape($rule_url . '?id=' . $rules_id); ?>">
                      <?php echo PluginFieldguardCompat::escape($row['name']); ?>
                    </a>
                  </td>
                  <td><?php echo PluginFieldguardCompat::escape(plugin_fieldguard_audience_label(
                      PluginFieldguardRule::getProfiles($rules_id),
                      'glpi_profiles',
                      __('Any profile', 'fieldguard')
                  )); ?></td>
                  <td><?php echo PluginFieldguardCompat::escape(plugin_fieldguard_audience_label(
                      PluginFieldguardRule::getGroups($rules_id),
                      'glpi_groups',
                      __('Any group', 'fieldguard')
                  )); ?></td>
                  <td class="text-center"><?php echo PluginFieldguardLock::countForRule($rules_id); ?></td>
                  <td class="text-center">
                    <?php if ((int) $row['is_active'] === 1) { ?>
                      <span class="badge bg-green-lt"><?php echo __('Yes'); ?></span>
                    <?php } else { ?>
                      <span class="badge bg-secondary-lt"><?php echo __('No'); ?></span>
                    <?php } ?>
                  </td>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-4">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title"><?php echo __('New rule', 'fieldguard'); ?></h3>
        </div>
        <div class="card-body">
          <form method="post" action="<?php echo PluginFieldguardCompat::escape($self_url); ?>">
            <label class="form-label" for="fieldguard-new-name"><?php echo __('Name'); ?></label>
            <input type="text" class="form-control" id="fieldguard-new-name" name="name" required
                   placeholder="<?php echo __('e.g. Technicians without status', 'fieldguard'); ?>">
            <div class="text-end mt-3">
              <button type="submit" class="btn btn-primary" name="add" value="1">
                <?php echo __('Add'); ?>
              </button>
            </div>
            <?php Html::closeForm(); ?>
        </div>
        <div class="card-footer fieldguard-hint">
          <?php echo __('Leave profiles and groups empty to apply the rule to everyone. Filling both means the rule only applies to someone who holds one of the profiles AND belongs to one of the groups.', 'fieldguard'); ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
Html::footer();
