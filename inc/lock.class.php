<?php

/**
 * A single "this field is locked by this rule" record.
 *
 * One row per (rule, itemtype, field) triplet. `field` holds the HTML `name`
 * attribute of the input as GLPI renders it, normalised by self::normalizeField()
 * so that `foo[]` and `foo` collapse onto the same key. Who the lock applies to
 * is the rule's business, not the lock's - see PluginFieldguardRule.
 */
class PluginFieldguardLock extends CommonDBTM
{
    public static $rightname = 'config';

    public const MODE_READONLY = 'readonly';
    public const MODE_HIDDEN   = 'hidden';

    /**
     * Input keys that must survive filtering whatever the configuration says.
     *
     * These are GLPI plumbing rather than user-editable fields; dropping them
     * would break the write instead of protecting it. Underscore-prefixed keys
     * in general *are* filtered, because real form fields such as `_actors`
     * live in that namespace and are exactly what admins want to lock.
     */
    private const NEVER_FILTERED = ['id', '_glpi_csrf_token', 'entities_id', 'is_recursive'];

    public static function getTypeName($nb = 0)
    {
        return __('Field guard', 'fieldguard');
    }

    public static function getModes(): array
    {
        return [
            self::MODE_READONLY => __('Read-only (greyed out)', 'fieldguard'),
            self::MODE_HIDDEN   => __('Hidden', 'fieldguard'),
        ];
    }

    /**
     * Itemtypes offered in the configuration UI, grouped into tabs.
     *
     * Only classes that actually exist in this GLPI install are returned, so the
     * list degrades gracefully across versions and editions.
     *
     * @return array<string, array<int, string>> tab label => itemtype list
     */
    public static function getItemtypeGroups(): array
    {
        $groups = [
            // Only the entry points: an ITIL object and the sub-items its own
            // form renders. Everything else a ticket has is reached by
            // navigating GLPI's tab rail inside the preview, which is both
            // shorter to display and open-ended - see getTabItemtypes().
            __('Assistance') => [
                'Ticket', 'ITILFollowup', 'TicketTask', 'ITILSolution', 'TicketValidation',
                'Problem', 'ProblemTask',
                'Change', 'ChangeTask', 'ChangeValidation',
                'TicketRecurrent',
            ],
            __('Assets') => [
                'Computer', 'Monitor', 'Software', 'NetworkEquipment', 'Peripheral',
                'Phone', 'Printer', 'Rack', 'Enclosure', 'PDU', 'Cable',
            ],
            __('Management') => [
                'SoftwareLicense', 'Budget', 'Supplier', 'Contact', 'Contract',
                'Document', 'Line', 'Certificate', 'Datacenter', 'Cluster',
                'Domain', 'Appliance', 'Database', 'DatabaseInstance',
            ],
            __('Tools') => [
                'Project', 'ProjectTask', 'Reminder', 'RSSFeed', 'KnowbaseItem',
            ],
            __('Administration') => [
                'User', 'Group', 'Entity', 'Profile', 'Location', 'Manufacturer', 'State',
            ],
        ];

        $available = [];
        foreach ($groups as $label => $itemtypes) {
            $existing = array_values(array_filter($itemtypes, static fn($it) => class_exists($it)));
            if ($existing !== []) {
                $available[$label] = $existing;
            }
        }

        return $available;
    }

    /**
     * Itemtypes rendered inside their parent's timeline.
     *
     * `front/itilfollowup.form.php` and friends are POST-only action handlers -
     * they answer 400/500 to a GET and never render anything. Their fields are
     * rendered inside the parent ITIL object's timeline instead, so the preview
     * loads the host's form and picks them up from there.
     *
     * @return array<string, string> itemtype => host itemtype
     */
    public static function getPreviewHosts(): array
    {
        return [
            'ITILFollowup'     => 'Ticket',
            'ITILSolution'     => 'Ticket',
            'TicketTask'       => 'Ticket',
            'TicketValidation' => 'Ticket',
            'ProblemTask'      => 'Problem',
            'ChangeTask'       => 'Change',
            'ChangeValidation' => 'Change',
        ];
    }

    /**
     * Itemtypes that live in a tab rather than on the parent's form.
     *
     * These get no pill of their own: the preview keeps GLPI's tab rail
     * visible, so the admin walks to Costs or Items the same way they would in
     * the real interface. They are listed here only so their fields are
     * recognised and their locks enforced once the admin gets there.
     *
     * @return array<int, string>
     */
    public static function getTabItemtypes(): array
    {
        return [
            'TicketCost', 'Item_Ticket', 'Ticket_Contract', 'ProjectTask_Ticket',
            'Problem_Ticket', 'Change_Ticket', 'KnowbaseItem_Item', 'Itil_Project',
            'ProblemCost', 'Item_Problem', 'Change_Problem', 'Notepad',
            'ChangeCost', 'Change_Item',
        ];
    }

    /**
     * How the configuration screen should preview one itemtype.
     *
     * `needs_id` marks a form that only exists for an already-saved item, either
     * because the itemtype is hosted in a parent's form or because the fields
     * worth locking (status, solution, approvals) are not rendered on creation.
     * `isolate` is only for timeline sub-items, where the host renders its own
     * fields and every sibling alongside the one being configured.
     *
     * @return array{itemtype: string, host: string, tab: string, needs_id: bool, isolate: bool}
     */
    public static function getPreviewSpec(string $itemtype): array
    {
        $host   = self::getPreviewHosts()[$itemtype] ?? $itemtype;
        $hosted = $host !== $itemtype;

        return [
            'itemtype' => $itemtype,
            'host'     => $host,
            // The tab is always named. GLPI remembers the last tab opened per
            // itemtype in the session, so leaving it out would make the preview
            // inherit whatever the admin happened to look at last.
            'tab'      => $host . '$main',
            'needs_id' => $hosted,
            'isolate'  => $hosted,
        ];
    }

    /**
     * Flat list of every itemtype the plugin may lock.
     *
     * Wider than the pill bar on purpose: the preview lets the admin navigate to
     * any tab, and a field is only clickable if its itemtype is listed here.
     *
     * @return array<int, string>
     */
    public static function getManagedItemtypes(): array
    {
        $available = array_filter(self::getTabItemtypes(), static fn($it) => class_exists($it));

        return array_values(array_unique(array_merge(
            ...array_values(self::getItemtypeGroups()),
            ...[array_values($available)]
        )));
    }

    /**
     * Itemtypes the enforcement hooks must be registered for.
     *
     * The managed list plus whatever the session actually holds locks on, so a
     * lock saved before an itemtype left the list above keeps being enforced
     * instead of silently becoming decorative.
     *
     * @return array<int, string>
     */
    public static function getEnforcedItemtypes(): array
    {
        return array_values(array_unique(array_merge(
            self::getManagedItemtypes(),
            array_keys(self::getLocksForCurrentSession())
        )));
    }

    public static function isManagedItemtype(string $itemtype): bool
    {
        return in_array($itemtype, self::getManagedItemtypes(), true);
    }

    /**
     * Extra ways to tell which itemtype a form writes to.
     *
     * The picker normally reads the itemtype off the form's `action`, which
     * works because GLPI posts an item to `front/<itemtype>.form.php`. Plugins
     * are free to break that: the Additional Fields plugin posts every custom
     * field tab to a single `container.form.php`, and the class the values
     * actually land in is generated per container, so only the server can name
     * it.
     *
     * A resolver says "read these hidden inputs, join them with a pipe, and
     * look the result up here". Adding support for another plugin is one more
     * entry, with nothing to change in the JavaScript.
     *
     * @param array<int, string>|null $only keep only entries pointing at these
     *                                      itemtypes; null keeps everything
     * @return array<int, array{keys: array<int, string>, map: array<string, string>}>
     */
    public static function getFormResolvers(?array $only = null): array
    {
        $resolvers = [];

        $fields = self::getAdditionalFieldsResolver();
        if ($fields !== null) {
            $resolvers[] = $fields;
        }

        if ($only === null) {
            return $resolvers;
        }

        // The runtime only needs to recognise the forms it actually holds locks
        // for, which keeps the tag it travels in small.
        $kept = [];
        foreach ($resolvers as $resolver) {
            $map = array_filter($resolver['map'], static fn($itemtype) => in_array($itemtype, $only, true));
            if ($map !== []) {
                $kept[] = ['keys' => $resolver['keys'], 'map' => $map];
            }
        }

        return $kept;
    }

    /**
     * Resolver for the Additional Fields plugin ("fields").
     *
     * Its tab form carries the host itemtype and the container id; the class
     * the values are written to is `PluginFields<host><container>`.
     *
     * @return array{keys: array<int, string>, map: array<string, string>}|null
     */
    private static function getAdditionalFieldsResolver(): ?array
    {
        global $DB;

        if (!($DB instanceof DBmysql) || !$DB->connected || !$DB->tableExists('glpi_plugin_fields_containers')) {
            return null;
        }

        $map = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'name', 'itemtypes'],
            'FROM'   => 'glpi_plugin_fields_containers',
        ]);

        foreach ($iterator as $row) {
            $itemtypes = json_decode((string) $row['itemtypes'], true);
            if (!is_array($itemtypes)) {
                continue;
            }

            foreach ($itemtypes as $host_itemtype) {
                $classname = self::getAdditionalFieldsClassname((string) $host_itemtype, (string) $row['name']);

                // The container's own table is the proof that the class is
                // real, and unlike class_exists() it needs no autoloader.
                if ($DB->tableExists(getTableForItemType($classname))) {
                    $map[$host_itemtype . '|' . $row['id']] = $classname;
                }
            }
        }

        return $map === [] ? null : [
            'keys' => ['itemtype', 'plugin_fields_containers_id'],
            'map'  => $map,
        ];
    }

    /**
     * Name of the class an Additional Fields container writes to.
     *
     * Asks the plugin when its classes are loadable, and falls back to its
     * naming rule otherwise: this runs from plugin init, where another plugin's
     * autoloader is not necessarily registered yet.
     */
    private static function getAdditionalFieldsClassname(string $host_itemtype, string $container_name): string
    {
        if (class_exists('PluginFieldsContainer')) {
            return PluginFieldsContainer::getClassname($host_itemtype, $container_name);
        }

        return 'PluginFields' . ucfirst(strtolower(
            str_replace('\\', '', $host_itemtype) . preg_replace('/s$/', '', $container_name)
        ));
    }

    /**
     * Itemtypes only a resolver can name.
     *
     * @return array<int, string>
     */
    public static function getResolvedItemtypes(): array
    {
        $itemtypes = [];
        foreach (self::getFormResolvers() as $resolver) {
            foreach ($resolver['map'] as $itemtype) {
                $itemtypes[$itemtype] = $itemtype;
            }
        }

        return array_values($itemtypes);
    }

    /**
     * May a lock be stored for this itemtype?
     *
     * Wider than isManagedItemtype(): a generated class such as an Additional
     * Fields container is never in a hand-written list, but it is a real
     * CommonDBTM whose add/update run through GLPI, so the enforcement hooks
     * work on it exactly as they do on a core itemtype.
     */
    public static function isLockableItemtype(string $itemtype): bool
    {
        return self::isManagedItemtype($itemtype)
            || in_array($itemtype, self::getResolvedItemtypes(), true);
    }

    /**
     * Collapse the variations GLPI uses for one logical field onto a single key.
     *
     * `users_id[]` and `users_id` are the same field; leading/trailing whitespace
     * and array indexes (`_actors[requester][0]`) are dropped.
     */
    public static function normalizeField(string $field): string
    {
        $field = trim($field);
        // Drop any trailing array notation, including indexed ones.
        $field = preg_replace('/(\[[^\]]*\])+$/', '', $field) ?? $field;

        return $field;
    }

    /**
     * Every lock held by one rule.
     *
     * @return array<string, array<string, string>> itemtype => (field => mode)
     */
    public static function getLocksForRule(int $rules_id): array
    {
        global $DB;

        $locks = [];
        $iterator = $DB->request([
            'SELECT' => ['itemtype', 'field', 'lock_mode'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_fieldguard_rules_id' => $rules_id],
        ]);

        foreach ($iterator as $row) {
            $locks[$row['itemtype']][$row['field']] = $row['lock_mode'];
        }

        return $locks;
    }

    public static function countForRule(int $rules_id): int
    {
        return countElementsInTable(self::getTable(), ['plugin_fieldguard_rules_id' => $rules_id]);
    }

    /**
     * Replace the whole lock set of a rule.
     *
     * The configuration screen holds every itemtype's locks in memory while the
     * admin picks fields, and posts the lot on save, so a wholesale replace is
     * what keeps the stored set and the screen in step - including removals,
     * which an incremental save would have no way to express.
     *
     * @param array<string, array<string, string>> $locks itemtype => (field => mode)
     * @return int number of locks stored
     */
    public static function setLocksForRule(int $rules_id, array $locks): int
    {
        global $DB;

        $rows = [];
        foreach ($locks as $itemtype => $fields) {
            if (!is_array($fields) || !self::isLockableItemtype((string) $itemtype)) {
                continue;
            }

            foreach ($fields as $field => $mode) {
                $field = self::normalizeField((string) $field);
                $mode  = (string) $mode;

                if ($field === '' || !array_key_exists($mode, self::getModes())) {
                    continue;
                }

                // Keyed so a field sent twice (a form rendered more than once in
                // the preview) collapses instead of tripping the unique key.
                $rows[$itemtype . "\0" . $field] = [
                    'plugin_fieldguard_rules_id' => $rules_id,
                    'itemtype'                  => (string) $itemtype,
                    'field'                     => $field,
                    'lock_mode'                 => $mode,
                ];
            }
        }

        $DB->delete(self::getTable(), ['plugin_fieldguard_rules_id' => $rules_id]);

        $lock = new self();
        foreach ($rows as $row) {
            $lock->add($row);
        }

        return count($rows);
    }

    /**
     * Every lock that applies to the current session, merged across rules.
     *
     * A field locked by more than one matching rule keeps the strictest mode:
     * hiding a field also makes it unwritable, so it wins over read-only.
     *
     * @return array<string, array<string, string>> itemtype => (field => mode)
     */
    public static function getLocksForCurrentSession(): array
    {
        global $DB;

        // Hit once per request: this runs on plugin init and again on every
        // add/update, which massive actions call in a loop.
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        // Runs from plugin init, so it also has to survive the request that is
        // installing the plugin, before the tables exist.
        if (
            !($DB instanceof DBmysql)
            || !$DB->connected
            || !$DB->tableExists(self::getTable())
            || !$DB->tableExists(PluginFieldguardRule::getTable())
        ) {
            return $cache = [];
        }

        $rules_ids = PluginFieldguardRule::getMatchingRuleIds();
        if ($rules_ids === []) {
            return $cache = [];
        }

        $locks = [];
        $iterator = $DB->request([
            'SELECT' => ['itemtype', 'field', 'lock_mode'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_fieldguard_rules_id' => $rules_ids],
        ]);

        foreach ($iterator as $row) {
            $current = $locks[$row['itemtype']][$row['field']] ?? null;
            if ($current === self::MODE_HIDDEN) {
                continue;
            }
            $locks[$row['itemtype']][$row['field']] = $row['lock_mode'];
        }

        return $cache = $locks;
    }

    /**
     * Strip locked fields from an item's input before GLPI writes it.
     *
     * This is the enforcement that matters: the CSS/JS layer only greys the field
     * in the browser and can be undone from the dev console, so the write path has
     * to drop the values independently.
     */
    public static function filterInput(CommonDBTM $item): void
    {
        if (!is_array($item->input) || $item->input === []) {
            return;
        }

        $itemtype = get_class($item);
        $locks    = self::getLocksForCurrentSession()[$itemtype] ?? [];
        if ($locks === []) {
            return;
        }

        foreach (array_keys($item->input) as $key) {
            $field = self::normalizeField((string) $key);
            if (in_array($field, self::NEVER_FILTERED, true)) {
                continue;
            }
            if (isset($locks[$field])) {
                unset($item->input[$key]);
            }
        }
    }

    public static function install(Migration $migration): void
    {
        global $DB;

        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $charset   = 'utf8mb4';
            $collation = 'utf8mb4_unicode_ci';

            PluginFieldguardCompat::dbQuery("CREATE TABLE `$table` (
                `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_fieldguard_rules_id` INT UNSIGNED NOT NULL DEFAULT '0',
                `itemtype`                  VARCHAR(255) NOT NULL,
                `field`                     VARCHAR(255) NOT NULL,
                `lock_mode`                 VARCHAR(20)  NOT NULL DEFAULT 'readonly',
                `date_creation`             TIMESTAMP NULL DEFAULT NULL,
                `date_mod`                  TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_fieldguard_rules_id`, `itemtype`, `field`),
                KEY `plugin_fieldguard_rules_id` (`plugin_fieldguard_rules_id`),
                KEY `itemtype` (`itemtype`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC;");
            $migration->displayMessage(sprintf(__('Table %s created', 'fieldguard'), $table));

            return;
        }

        if ($DB->fieldExists($table, 'profiles_id')) {
            self::migrateProfilesToRules($migration);
        }
    }

    /**
     * Upgrade from the pre-rule schema.
     *
     * Locks used to hang off a profile directly. Each profile that held locks
     * becomes one rule targeting that profile, which keeps every existing lock
     * working exactly as before the upgrade.
     */
    private static function migrateProfilesToRules(Migration $migration): void
    {
        global $DB;

        $table = self::getTable();

        PluginFieldguardCompat::dbQuery(
            "ALTER TABLE `$table` ADD COLUMN `plugin_fieldguard_rules_id` INT UNSIGNED NOT NULL DEFAULT '0'"
        );

        $profiles_ids = [];
        foreach ($DB->request(['SELECT' => 'profiles_id', 'DISTINCT' => true, 'FROM' => $table]) as $row) {
            $profiles_ids[] = (int) $row['profiles_id'];
        }

        $rule    = new PluginFieldguardRule();
        $profile = new Profile();

        foreach ($profiles_ids as $profiles_id) {
            $name = $profile->getFromDB($profiles_id)
                ? $profile->fields['name']
                : sprintf(__('Profile %d', 'fieldguard'), $profiles_id);

            $rules_id = (int) $rule->add(['name' => $name, 'is_active' => 1]);
            if ($rules_id <= 0) {
                continue;
            }

            PluginFieldguardRule::setAudience($rules_id, [$profiles_id], []);
            $DB->update($table, ['plugin_fieldguard_rules_id' => $rules_id], ['profiles_id' => $profiles_id]);
        }

        PluginFieldguardCompat::dbQuery("ALTER TABLE `$table` DROP INDEX `unicity`");
        PluginFieldguardCompat::dbQuery("ALTER TABLE `$table` DROP INDEX `profiles_id`");
        PluginFieldguardCompat::dbQuery("ALTER TABLE `$table` DROP COLUMN `profiles_id`");
        PluginFieldguardCompat::dbQuery(
            "ALTER TABLE `$table` ADD UNIQUE KEY `unicity` (`plugin_fieldguard_rules_id`, `itemtype`, `field`)"
        );
        PluginFieldguardCompat::dbQuery(
            "ALTER TABLE `$table` ADD KEY `plugin_fieldguard_rules_id` (`plugin_fieldguard_rules_id`)"
        );

        $migration->displayMessage(
            sprintf(__('%d profile(s) converted into lock rules', 'fieldguard'), count($profiles_ids))
        );
    }

    public static function uninstall(): void
    {
        global $DB;

        $table = self::getTable();
        if ($DB->tableExists($table)) {
            PluginFieldguardCompat::dbQuery("DROP TABLE `$table`");
        }
    }
}
