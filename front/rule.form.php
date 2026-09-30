<?php

/**
 * Field Guard - rule editor.
 *
 * Top half: who the rule applies to. Bottom half: the live preview, where every
 * form GLPI can render is loaded in a same-origin iframe and its fields are
 * clicked to lock them. Picks are held in the browser and written in one go by
 * the Save button, so switching between forms never loses a selection.
 */

// GLPI 10 executes plugin scripts directly and expects them to bootstrap the
// core themselves. GLPI 11 boots its kernel before requiring the file, where
// including this again would be an error.
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

// GLPI 11 requires legacy scripts from inside a controller method, so the
// globals have to be imported explicitly.
global $CFG_GLPI;

Session::checkRight('config', UPDATE);

$plugin_dir = $CFG_GLPI['root_doc'] . PluginFieldguardCompat::pluginWebDir();
$self_url   = PluginFieldguardCompat::scriptUrl('front/rule.form.php');
$list_url   = PluginFieldguardCompat::scriptUrl('front/config.form.php');

$rule = new PluginFieldguardRule();

if (isset($_POST['update'])) {
    $rules_id = (int) ($_POST['id'] ?? 0);

    $rule->update([
        'id'        => $rules_id,
        'name'      => (string) ($_POST['name'] ?? ''),
        'is_active' => (int) ($_POST['is_active'] ?? 0),
        'comment'   => (string) ($_POST['comment'] ?? ''),
    ]);

    PluginFieldguardRule::setAudience(
        $rules_id,
        (array) ($_POST['fieldguard_profiles'] ?? []),
        (array) ($_POST['fieldguard_groups'] ?? [])
    );

    Session::addMessageAfterRedirect(__('Rule saved', 'fieldguard'), true, INFO);
    Html::back();
}

if (isset($_POST['purge'])) {
    $rule->delete(['id' => (int) ($_POST['id'] ?? 0)], true);
    Html::redirect($list_url);
}

$rules_id = (int) ($_GET['id'] ?? 0);
if ($rules_id <= 0 || !$rule->getFromDB($rules_id)) {
    Html::redirect($list_url);
}

/* ------------------------------------------------------------- audience --- */

$profiles = [];
foreach ((new Profile())->find([], ['name']) as $profile) {
    $profiles[(int) $profile['id']] = $profile['name'];
}

$groups = [];
foreach ((new Group())->find(getEntitiesRestrictCriteria('glpi_groups', '', '', true), ['completename']) as $group) {
    $groups[(int) $group['id']] = $group['completename'] !== '' ? $group['completename'] : $group['name'];
}

/* -------------------------------------------------------------- preview --- */

$itemtype_groups = PluginFieldguardLock::getItemtypeGroups();
$js_groups       = [];

foreach ($itemtype_groups as $group_label => $itemtypes) {
    foreach ($itemtypes as $itemtype) {
        $spec = PluginFieldguardLock::getPreviewSpec($itemtype);

        $js_groups[$group_label][] = [
            'itemtype'   => $itemtype,
            'label'      => $itemtype::getTypeName(1),
            // Hosted itemtypes are previewed through their parent's form.
            'url'        => Toolbox::getItemTypeFormURL($spec['host'], false),
            'host'       => $spec['host'],
            'host_label' => $spec['host']::getTypeName(1),
            'tab'        => $spec['tab'],
            'needs_id'   => $spec['needs_id'],
            'isolate'    => $spec['isolate'],
        ];
    }
}

$config = [
    'root_doc'   => $CFG_GLPI['root_doc'],
    'ajax_base'  => $plugin_dir . '/ajax',
    'picker_css' => $CFG_GLPI['root_doc'] . PluginFieldguardCompat::assetUrl('css/picker.css'),
    'csrf_token' => Session::getNewCSRFToken(),
    'version'    => PluginFieldguardCompat::assetVersion('css/picker.css'),
    'rules_id'   => $rules_id,
    'groups'     => $js_groups,
    'modes'      => PluginFieldguardLock::getModes(),
    'itemtypes'  => PluginFieldguardLock::getManagedItemtypes(),
    // Forms whose action does not name their itemtype; see getFormResolvers().
    'resolvers'  => PluginFieldguardLock::getFormResolvers(),
    // Cast so an empty set serialises as `{}` and not `[]`.
    'locks'      => (object) PluginFieldguardLock::getLocksForRule($rules_id),
    'i18n'       => [
        'loading'      => __('Loading form…', 'fieldguard'),
        'locked'       => __('locked field(s)', 'fieldguard'),
        'total'        => __('%d locked field(s) in this rule', 'fieldguard'),
        'saved'        => __('Saved', 'fieldguard'),
        'saving'       => __('Saving…', 'fieldguard'),
        'error'        => __('Could not save the change', 'fieldguard'),
        'unsaved'      => __('Unsaved changes', 'fieldguard'),
        'leave'        => __('This rule has unsaved lock changes.', 'fieldguard'),
        'confirm_wipe' => __('Remove every lock this rule holds on this form?', 'fieldguard'),
        'unsupported'  => __('This form has no field that can be locked.', 'fieldguard'),
        'needs_id'     => __('This form only exists for a saved item. Enter an existing %s ID above.', 'fieldguard'),
        'pick_form'    => __('Pick a form above to start locking fields.', 'fieldguard'),
        'foreign'      => __('Will be locked on %s', 'fieldguard'),
        'navigate'     => __('Use the tabs on the left of the preview to reach costs, items, contracts and anything else a form has.', 'fieldguard'),
    ],
];

Html::header(
    $rule->fields['name'],
    $self_url,
    'config',
    'plugins'
);

// Stamp each asset with its own mtime. Html::css()/Html::script() default to
// GLPI's core version, which does not change when the plugin does, so browsers
// would keep serving a stale copy (they are sent with a one-month max-age).
echo Html::css(
    PluginFieldguardCompat::assetUrl('css/picker.css'),
    ['version' => PluginFieldguardCompat::assetVersion('css/picker.css')]
);
?>

<div class="fieldguard-config">
  <div class="mb-3">
    <a href="<?php echo PluginFieldguardCompat::escape($list_url); ?>" class="btn btn-sm btn-outline-secondary">
      <?php echo PluginFieldguardCompat::escape(PluginFieldguardRule::getTypeName(2)); ?>
    </a>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <form method="post" action="<?php echo PluginFieldguardCompat::escape($self_url); ?>">
        <input type="hidden" name="id" value="<?php echo $rules_id; ?>">

        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label" for="fieldguard-name"><?php echo __('Name'); ?></label>
            <input type="text" class="form-control" id="fieldguard-name" name="name"
                   value="<?php echo PluginFieldguardCompat::escape($rule->fields['name']); ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label"><?php echo __('Active'); ?></label>
            <div>
              <?php Dropdown::showYesNo('is_active', $rule->fields['is_active']); ?>
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label"><?php echo _n('Profile', 'Profiles', 2); ?></label>
            <?php
                Dropdown::showFromArray('fieldguard_profiles', $profiles, [
                    'multiple' => true,
                    'values'   => PluginFieldguardRule::getProfiles($rules_id),
                    'width'    => '100%',
                    'rand'     => 'fieldguardprofiles',
                ]);
            ?>
            <div class="form-hint"><?php echo __('Empty: every profile.', 'fieldguard'); ?></div>
          </div>

          <div class="col-md-6">
            <label class="form-label"><?php echo _n('Group', 'Groups', 2); ?></label>
            <?php
                Dropdown::showFromArray('fieldguard_groups', $groups, [
                    'multiple' => true,
                    'values'   => PluginFieldguardRule::getGroups($rules_id),
                    'width'    => '100%',
                    'rand'     => 'fieldguardgroups',
                ]);
            ?>
            <div class="form-hint"><?php echo __('Empty: every group.', 'fieldguard'); ?></div>
          </div>

          <div class="col-12">
            <label class="form-label" for="fieldguard-comment"><?php echo __('Comments'); ?></label>
            <textarea class="form-control" id="fieldguard-comment" name="comment" rows="2"><?php
                echo PluginFieldguardCompat::escape($rule->fields['comment']);
            ?></textarea>
          </div>
        </div>

        <div class="d-flex justify-content-between mt-3">
          <button type="submit" class="btn btn-outline-danger" name="purge" value="1"
                  onclick="return confirm('<?php echo PluginFieldguardCompat::escape(__('Confirm the final deletion?')); ?>');">
            <?php echo __('Delete permanently'); ?>
          </button>
          <button type="submit" class="btn btn-primary" name="update" value="1">
            <?php echo __('Save'); ?>
          </button>
        </div>
        <?php Html::closeForm(); ?>
    </div>
    <div class="card-footer fieldguard-hint">
      <?php echo __('Profiles and groups are combined with AND: a rule naming both only applies to someone who holds one of the profiles and belongs to one of the groups.', 'fieldguard'); ?>
    </div>
  </div>

  <div class="card">
    <ul class="nav nav-tabs" id="fieldguard-group-tabs" role="tablist">
      <?php $i = 0; foreach (array_keys($js_groups) as $group_label) { ?>
        <li class="nav-item" role="presentation">
          <button class="nav-link <?php echo $i === 0 ? 'active' : ''; ?>"
                  type="button"
                  data-fieldguard-group="<?php echo PluginFieldguardCompat::escape($group_label); ?>">
            <?php echo PluginFieldguardCompat::escape($group_label); ?>
          </button>
        </li>
      <?php $i++; } ?>
    </ul>

    <div class="card-body border-bottom py-2">
      <div id="fieldguard-itemtypes" class="fieldguard-pills"></div>
    </div>

    <div class="card-body border-bottom py-2 d-flex align-items-center gap-3 flex-wrap">
      <div class="d-flex align-items-center gap-2">
        <label class="form-label mb-0 text-nowrap" for="fieldguard-item-id">
          <?php echo __('Item ID', 'fieldguard'); ?>
        </label>
        <input type="number" min="1" step="1" id="fieldguard-item-id"
               class="form-control form-control-sm" style="width: 8rem"
               placeholder="<?php echo __('new item', 'fieldguard'); ?>">
        <span class="fieldguard-hint">
          <?php echo __('Leave empty for the creation form. Fill it to reach fields that only exist on a saved item.', 'fieldguard'); ?>
        </span>
      </div>
      <div class="d-flex align-items-center gap-2">
        <span class="fieldguard-hint">
          <i class="ti ti-arrow-left"></i>
          <?php echo __('Use the tabs on the left of the preview to reach costs, items, contracts and anything else a form has.', 'fieldguard'); ?>
        </span>
      </div>
      <div class="d-flex align-items-center gap-2">
        <label class="form-label mb-0 text-nowrap" for="fieldguard-mode"><?php echo __('Lock mode', 'fieldguard'); ?></label>
        <select id="fieldguard-mode" class="form-select form-select-sm" style="width: auto">
          <?php foreach (PluginFieldguardLock::getModes() as $value => $label) { ?>
            <option value="<?php echo PluginFieldguardCompat::escape($value); ?>"><?php echo PluginFieldguardCompat::escape($label); ?></option>
          <?php } ?>
        </select>
      </div>
      <span id="fieldguard-counter" class="badge bg-secondary">0 <?php echo PluginFieldguardCompat::escape($config['i18n']['locked']); ?></span>
      <span id="fieldguard-total" class="fieldguard-hint"></span>
      <span id="fieldguard-status" class="fieldguard-status text-muted"></span>
      <div class="ms-auto d-flex gap-2">
        <button type="button" id="fieldguard-reload" class="btn btn-sm btn-outline-secondary">
          <?php echo __('Reload preview', 'fieldguard'); ?>
        </button>
        <button type="button" id="fieldguard-clear" class="btn btn-sm btn-outline-danger">
          <?php echo __('Clear locks on this form', 'fieldguard'); ?>
        </button>
        <button type="button" id="fieldguard-save" class="btn btn-sm btn-primary">
          <?php echo __('Save locks', 'fieldguard'); ?>
        </button>
      </div>
    </div>

    <div class="card-body p-0">
      <div id="fieldguard-empty" class="p-4 text-center text-muted">
        <?php echo PluginFieldguardCompat::escape($config['i18n']['pick_form']); ?>
      </div>
      <div id="fieldguard-frame-wrap" class="fieldguard-frame-wrap d-none">
        <iframe id="fieldguard-frame" class="fieldguard-frame" title="<?php echo __('Form preview', 'fieldguard'); ?>"></iframe>
      </div>
    </div>
  </div>
</div>

<script type="application/json" id="fieldguard-config">
<?php echo json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
</script>

<?php
echo Html::script(
    PluginFieldguardCompat::assetUrl('js/picker.js'),
    ['version' => PluginFieldguardCompat::assetVersion('js/picker.js')]
);

Html::footer();
