<?php

/**
 * A lock rule: a named set of locks plus the audience it applies to.
 *
 * The audience is two independent lists, profiles and groups. An empty list
 * means "any", and the two are combined with AND: a rule naming both a profile
 * and a group only applies to someone who holds that profile *and* belongs to
 * that group. Locks themselves hang off the rule, never off a profile, so the
 * same set of fields can be granted to several audiences without duplication.
 */
class PluginFieldguardRule extends CommonDBTM
{
    public static $rightname = 'config';

    public $dohistory = false;

    public static function getTypeName($nb = 0)
    {
        return _n('Lock rule', 'Lock rules', $nb, 'fieldguard');
    }

    public static function getIcon()
    {
        return 'ti ti-lock';
    }

    public function prepareInputForAdd($input)
    {
        return $this->prepareInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareInput($input);
    }

    private function prepareInput($input)
    {
        if (array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            $input['name'] = __('Untitled rule', 'fieldguard');
        }

        return $input;
    }

    public function cleanDBonPurge()
    {
        $rules_id = (int) $this->fields['id'];

        (new PluginFieldguardLock())->deleteByCriteria(['plugin_fieldguard_rules_id' => $rules_id], true);
        (new PluginFieldguardRuleProfile())->deleteByCriteria(['plugin_fieldguard_rules_id' => $rules_id], true);
        (new PluginFieldguardRuleGroup())->deleteByCriteria(['plugin_fieldguard_rules_id' => $rules_id], true);
    }

    /* ------------------------------------------------------------ audience --- */

    /**
     * Profile ids a rule is restricted to. Empty means every profile.
     *
     * @return array<int, int>
     */
    public static function getProfiles(int $rules_id): array
    {
        return self::getLinkedIds(PluginFieldguardRuleProfile::class, 'profiles_id', $rules_id);
    }

    /**
     * Group ids a rule is restricted to. Empty means every group.
     *
     * @return array<int, int>
     */
    public static function getGroups(int $rules_id): array
    {
        return self::getLinkedIds(PluginFieldguardRuleGroup::class, 'groups_id', $rules_id);
    }

    /**
     * @param class-string<CommonDBTM> $link_class
     * @return array<int, int>
     */
    private static function getLinkedIds(string $link_class, string $field, int $rules_id): array
    {
        global $DB;

        $ids = [];
        $iterator = $DB->request([
            'SELECT' => $field,
            'FROM'   => $link_class::getTable(),
            'WHERE'  => ['plugin_fieldguard_rules_id' => $rules_id],
        ]);

        foreach ($iterator as $row) {
            $ids[] = (int) $row[$field];
        }

        return $ids;
    }

    /**
     * Replace the audience of a rule.
     *
     * @param array<int, int> $profiles_ids
     * @param array<int, int> $groups_ids
     */
    public static function setAudience(int $rules_id, array $profiles_ids, array $groups_ids): void
    {
        self::replaceLinks(PluginFieldguardRuleProfile::class, 'profiles_id', $rules_id, $profiles_ids);
        self::replaceLinks(PluginFieldguardRuleGroup::class, 'groups_id', $rules_id, $groups_ids);
    }

    /**
     * @param class-string<CommonDBTM> $link_class
     * @param array<int, int>          $ids
     */
    private static function replaceLinks(string $link_class, string $field, int $rules_id, array $ids): void
    {
        $link = new $link_class();
        $link->deleteByCriteria(['plugin_fieldguard_rules_id' => $rules_id], true);

        $ids = array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0));
        foreach ($ids as $id) {
            $link->add([
                'plugin_fieldguard_rules_id' => $rules_id,
                $field                      => $id,
            ]);
        }
    }

    /* ------------------------------------------------------------ matching --- */

    /**
     * Ids of the active rules that apply to the current session.
     *
     * The three tables are tiny and the whole thing is resolved in PHP rather
     * than in SQL: expressing "no restriction OR a matching restriction" for two
     * independent link tables needs correlated subqueries, which the query
     * builder writes differently across the supported GLPI versions.
     *
     * @return array<int, int>
     */
    public static function getMatchingRuleIds(): array
    {
        global $DB;

        // Resolved once per request: this feeds both the page payload and every
        // add/update, which massive actions call in a loop.
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $profiles_id = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        $groups_ids  = array_map('intval', $_SESSION['glpigroups'] ?? []);

        $rules_ids = [];
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => self::getTable(), 'WHERE' => ['is_active' => 1]]) as $row) {
            $rules_ids[] = (int) $row['id'];
        }

        if ($rules_ids === []) {
            return $cache = [];
        }

        $profiles = self::loadLinks(PluginFieldguardRuleProfile::class, 'profiles_id', $rules_ids);
        $groups   = self::loadLinks(PluginFieldguardRuleGroup::class, 'groups_id', $rules_ids);

        $matching = [];
        foreach ($rules_ids as $rules_id) {
            $wanted_profiles = $profiles[$rules_id] ?? [];
            $wanted_groups   = $groups[$rules_id] ?? [];

            if ($wanted_profiles !== [] && !in_array($profiles_id, $wanted_profiles, true)) {
                continue;
            }
            if ($wanted_groups !== [] && array_intersect($wanted_groups, $groups_ids) === []) {
                continue;
            }

            $matching[] = $rules_id;
        }

        return $cache = $matching;
    }

    /**
     * @param class-string<CommonDBTM> $link_class
     * @param array<int, int>          $rules_ids
     * @return array<int, array<int, int>> rule id => linked ids
     */
    private static function loadLinks(string $link_class, string $field, array $rules_ids): array
    {
        global $DB;

        $links = [];
        $iterator = $DB->request([
            'SELECT' => ['plugin_fieldguard_rules_id', $field],
            'FROM'   => $link_class::getTable(),
            'WHERE'  => ['plugin_fieldguard_rules_id' => $rules_ids],
        ]);

        foreach ($iterator as $row) {
            $links[(int) $row['plugin_fieldguard_rules_id']][] = (int) $row[$field];
        }

        return $links;
    }

    /* --------------------------------------------------------------- setup --- */

    public static function install(Migration $migration): void
    {
        global $DB;

        $charset   = 'utf8mb4';
        $collation = 'utf8mb4_unicode_ci';

        $table = self::getTable();
        if (!$DB->tableExists($table)) {
            PluginFieldguardCompat::dbQuery("CREATE TABLE `$table` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`          VARCHAR(255) NOT NULL DEFAULT '',
                `is_active`     TINYINT NOT NULL DEFAULT '1',
                `comment`       TEXT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod`      TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `name` (`name`),
                KEY `is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC;");
            $migration->displayMessage(sprintf(__('Table %s created', 'fieldguard'), $table));
        }

        foreach (
            [
                PluginFieldguardRuleProfile::getTable() => 'profiles_id',
                PluginFieldguardRuleGroup::getTable()   => 'groups_id',
            ] as $link_table => $field
        ) {
            if ($DB->tableExists($link_table)) {
                continue;
            }

            PluginFieldguardCompat::dbQuery("CREATE TABLE `$link_table` (
                `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_fieldguard_rules_id` INT UNSIGNED NOT NULL DEFAULT '0',
                `$field`                    INT UNSIGNED NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`plugin_fieldguard_rules_id`, `$field`),
                KEY `$field` (`$field`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC;");
            $migration->displayMessage(sprintf(__('Table %s created', 'fieldguard'), $link_table));
        }
    }

    public static function uninstall(): void
    {
        global $DB;

        foreach (
            [
                PluginFieldguardRuleProfile::getTable(),
                PluginFieldguardRuleGroup::getTable(),
                self::getTable(),
            ] as $table
        ) {
            if ($DB->tableExists($table)) {
                PluginFieldguardCompat::dbQuery("DROP TABLE `$table`");
            }
        }
    }
}
