<?php
if (!defined('ABSPATH')) { exit; }
/** Edition-scoped catalogue administration and public read-only browsing. */
final class LS_Armoury {
    public static function boot(): void {
        add_action('admin_post_ls_armoury_save', array(__CLASS__, 'save'));
        add_action('admin_post_ls_detachment_save', array(__CLASS__, 'save_detachment'));
        add_action('admin_post_ls_armoury_manage', array(__CLASS__, 'manage'));
        add_action('admin_post_ls_armoury_publish', array(__CLASS__, 'publish'));
        add_action('admin_post_ls_armoury_edition_transition', array(__CLASS__, 'transition_edition'));
        add_action('admin_post_ls_armoury_clone', array(__CLASS__, 'clone_release'));
        add_action('admin_post_ls_armoury_release_transition', array(__CLASS__, 'transition_release'));
        add_action('admin_post_ls_armoury_delete_release', array(__CLASS__, 'delete_release'));
        add_action('admin_post_ls_rule_save', array(__CLASS__, 'rule_save'));
        add_action('admin_post_ls_rule_delete', array(__CLASS__, 'rule_delete'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_assets'));
    }
    public static function admin_assets(string $hook): void {
        if ($hook === 'toplevel_page_legion-strategium') {
            wp_enqueue_style('ls-armoury-admin', LS_URL . 'assets/armoury-admin.css', array(), LS_VERSION);
            wp_enqueue_script('ls-datasheet-editor', LS_URL . 'assets/datasheet-editor.js', array(), LS_VERSION, true);
        }
    }

    /** Parse a structured, edition-neutral profile without discarding existing unit data. */
    public static function profile_fields(): array {
        return array('movement'=>'Movement', 'weapon_skill'=>'Weapon Skill', 'ballistic_skill'=>'Ballistic Skill',
            'strength'=>'Strength', 'toughness'=>'Toughness', 'wounds'=>'Wounds', 'initiative'=>'Initiative',
            'attacks'=>'Attacks', 'leadership'=>'Leadership', 'save'=>'Save');
    }

    public static function sanitise_profile(array $input): array {
        $profile = array();
        foreach (self::profile_fields() as $key => $label) {
            if (!isset($input[$key]) || !is_scalar($input[$key])) { continue; }
            $value = trim((string)$input[$key]);
            if ($value === '') { continue; }
            if (strlen($value) > 32 || !preg_match('/^[a-zA-Z0-9+\-*\/ .]+$/', $value)) { continue; }
            $profile[$key] = $value;
        }
        return $profile;
    }

    /** Edition-specific profile keys can be extended without changing the database schema. */
    public static function datasheet_fields(): array {
        return array_merge(self::profile_fields(), array('cool'=>'Cool', 'willpower'=>'Willpower',
            'intelligence'=>'Intelligence', 'invulnerable_save'=>'Invulnerable Save'));
    }

    /** Sanitise a bounded list of named model profiles. Never accept arbitrary markup. */
    public static function sanitise_model_profiles($input): array {
        if (!is_array($input)) { return array(); }
        $result = array();
        foreach (array_slice($input, 0, 20) as $entry) {
            if (!is_array($entry)) { continue; }
            $name = isset($entry['name']) && is_scalar($entry['name']) ? trim((string)$entry['name']) : '';
            if ($name === '' || strlen($name) > 100 || !preg_match('/^[\p{L}\p{N} .\x27()\/-]+$/u', $name)) { continue; }
            $stats = array();
            foreach (self::datasheet_fields() as $key => $label) {
                if (!isset($entry[$key]) || !is_scalar($entry[$key])) { continue; }
                $value = trim((string)$entry[$key]);
                if ($value !== '' && strlen($value) <= 32 && preg_match('/^[a-zA-Z0-9+*\/-]+$/', $value)) { $stats[$key] = $value; }
            }
            $result[] = array('name'=>$name, 'characteristics'=>$stats);
        }
        return $result;
    }

    public static function sanitise_composition($input): array {
        if (!is_array($input)) { return array(); }
        $result = array();
        foreach (array('minimum_models','maximum_models','starting_models','additional_model_points') as $key) {
            if (!isset($input[$key]) || !is_scalar($input[$key])) { continue; }
            $number = filter_var($input[$key], FILTER_VALIDATE_INT);
            if ($number !== false && $number >= 0 && $number <= 100000) { $result[$key] = $number; }
        }
        if (isset($result['minimum_models'], $result['maximum_models']) && $result['minimum_models'] > $result['maximum_models']) { return array(); }
        if (isset($result['starting_models'], $result['minimum_models']) && $result['starting_models'] < $result['minimum_models']) { return array(); }
        if (isset($result['starting_models'], $result['maximum_models']) && $result['starting_models'] > $result['maximum_models']) { return array(); }
        return $result;
    }

    /** Release-local, reusable equipment. Slugs survive release cloning. */
    public static function sanitise_equipment($input): array {
        if (!is_array($input)) { return array(); }
        $type = isset($input['type']) && is_scalar($input['type']) ? (string)$input['type'] : 'equipment';
        if (!in_array($type, array('ranged','melee','equipment'), true)) { $type = 'equipment'; }
        $result = array('type'=>$type, 'profile'=>array(), 'rules'=>array());
        foreach (array('range','strength','ap','damage','attacks','special','initiative_modifier') as $key) {
            if (!isset($input[$key]) || !is_scalar($input[$key])) { continue; }
            $value = trim((string)$input[$key]);
            if ($key === 'initiative_modifier') {
                // A weapon modifier is relative to the model's base Initiative.
                if (preg_match('/^[+-]?(?:0|[1-9][0-9]?)$/', $value)) {
                    $number = (int)$value;
                    if ($number >= -99 && $number <= 99) { $result['profile'][$key] = $number > 0 ? '+' . $number : (string)$number; }
                }
                continue;
            }
            if ($value !== '' && strlen($value) <= 80 && preg_match('/^[\p{L}\p{N} +*\/.,():\x27-]+$/u', $value)) { $result['profile'][$key] = $value; }
        }
        if (isset($input['rules']) && is_scalar($input['rules'])) {
            foreach (array_slice(explode(',', (string)$input['rules']), 0, 20) as $rule) {
                $rule = trim($rule);
                if ($rule !== '' && strlen($rule) <= 100 && preg_match('/^[\p{L}\p{N} \x27()-]+$/u', $rule)) { $result['rules'][] = $rule; }
            }
        }
        return $result;
    }

    public static function sanitise_equipment_links($input, array $allowed_slugs, array $model_names): array {
        if (!is_array($input)) { return array(); }
        $allowed = array_fill_keys($allowed_slugs, true);
        $models = array_fill_keys($model_names, true);
        $result = array();
        foreach (array_slice($input, 0, 40) as $entry) {
            if (!is_array($entry)) { continue; }
            $slug = isset($entry['slug']) && is_scalar($entry['slug']) ? (string)$entry['slug'] : '';
            $model = isset($entry['model']) && is_scalar($entry['model']) ? (string)$entry['model'] : '';
            if ($slug === '' || !isset($allowed[$slug]) || ($model !== '' && !isset($models[$model]))) { continue; }
            $link = array('slug'=>$slug, 'model'=>$model);
            if (!in_array($link, $result, true)) { $result[] = $link; }
        }
        return $result;
    }

    /** Draft-only option definitions; references are restricted to the unit's release. */
    public static function sanitise_unit_options($input, array $allowed_slugs, array $model_names): array {
        if (!is_array($input)) { return array(); }
        $allowed = array_fill_keys($allowed_slugs, true);
        $models = array_fill_keys($model_names, true);
        $result = array();
        foreach (array_slice($input, 0, 30) as $entry) {
            if (!is_array($entry)) { continue; }
            $name = isset($entry['name']) && is_scalar($entry['name']) ? trim((string)$entry['name']) : '';
            $type = isset($entry['type']) && is_scalar($entry['type']) ? (string)$entry['type'] : '';
            $model = isset($entry['model']) && is_scalar($entry['model']) ? (string)$entry['model'] : '';
            $from = isset($entry['from']) && is_scalar($entry['from']) ? (string)$entry['from'] : '';
            $to = isset($entry['to']) && is_scalar($entry['to']) ? (string)$entry['to'] : '';
            if ($name === '' || strlen($name) > 100 || !preg_match('/^[\p{L}\p{N} .\x27()\/-]+$/u', $name)) { continue; }
            if (!in_array($type, array('add','replace','model'), true)) { continue; }
            if ($model !== '' && !isset($models[$model])) { continue; }
            if ($type === 'model') {
                if ($model === '' || $from !== '' || $to !== '') { continue; }
            } elseif (!isset($allowed[$to]) || ($type === 'replace' && !isset($allowed[$from])) || ($type === 'add' && $from !== '')) { continue; }
            $points = isset($entry['points']) && is_scalar($entry['points']) ? filter_var($entry['points'], FILTER_VALIDATE_INT) : false;
            $min = isset($entry['min']) && is_scalar($entry['min']) ? filter_var($entry['min'], FILTER_VALIDATE_INT) : false;
            $max = isset($entry['max']) && is_scalar($entry['max']) ? filter_var($entry['max'], FILTER_VALIDATE_INT) : false;
            if ($points === false || $points < -100000 || $points > 100000 || $min === false || $max === false || $min < 0 || $max < 1 || $max > 100 || $min > $max) { continue; }
            $result[] = array('name'=>$name, 'type'=>$type, 'model'=>$model, 'from'=>$from, 'to'=>$to, 'points'=>$points, 'min'=>$min, 'max'=>$max);
        }
        return $result;
    }

    /** Return a user-facing reason instead of silently dropping a populated option row. */
    public static function unit_option_error($input, array $allowed_slugs, array $model_names): string {
        if (!is_array($input)) { return 'Upgrade options must be an array.'; }
        foreach (array_slice($input, 0, 30) as $index => $entry) {
            if (!is_array($entry)) { return 'An upgrade option has invalid fields.'; }
            $name = isset($entry['name']) && is_scalar($entry['name']) ? trim((string)$entry['name']) : '';
            $from = isset($entry['from']) && is_scalar($entry['from']) ? (string)$entry['from'] : '';
            $to = isset($entry['to']) && is_scalar($entry['to']) ? (string)$entry['to'] : '';
            $type = isset($entry['type']) && is_scalar($entry['type']) ? (string)$entry['type'] : '';
            $model = isset($entry['model']) && is_scalar($entry['model']) ? (string)$entry['model'] : '';
            // Completely empty spare rows are intentionally ignored.
            if ($name === '' && $from === '' && $to === '' && $model === '') { continue; }
            $number = $index + 1;
            if ($name === '') { return 'Option ' . $number . ': enter an option name before saving.'; }
            if (!in_array($type, array('add','replace','model'), true)) { return 'Option ' . $number . ': choose a valid option type.'; }
            if ($type === 'add' && $from !== '') { return 'Option ' . $number . ': Add equipment cannot replace equipment. Choose Exchange equipment instead.'; }
            if ($type === 'replace' && ($from === '' || $to === '')) { return 'Option ' . $number . ': choose both the old and new equipment.'; }
            if ($type === 'add' && $to === '') { return 'Option ' . $number . ': choose equipment to add.'; }
            if ($type === 'model' && $model === '') { return 'Option ' . $number . ': choose an eligible model.'; }
            if (count(self::sanitise_unit_options(array($entry), $allowed_slugs, $model_names)) !== 1) {
                return 'Option ' . $number . ': invalid model, equipment, points or limits. Equipment must belong to this ruleset release.';
            }
        }
        return '';
    }

    /** Calculate an option subtotal only after validating each selection against its limits. */
    public static function option_points_total(array $options, array $selections): ?int {
        $total = 0;
        foreach ($selections as $index => $quantity) {
            if (!is_int($index) && !(is_string($index) && ctype_digit($index))) { return null; }
            if (!isset($options[(int)$index]) || !is_scalar($quantity)) { return null; }
            $count = filter_var($quantity, FILTER_VALIDATE_INT);
            if ($count === false || $count < (int)$options[(int)$index]['min'] || $count > (int)$options[(int)$index]['max']) { return null; }
            $total += $count * (int)$options[(int)$index]['points'];
        }
        return $total;
    }

    /** Rules are reusable within a release; never resolve names across releases. */
    public static function sanitise_rule(array $input): array {
        $type = isset($input['type']) && is_scalar($input['type']) ? trim((string)$input['type']) : 'special';
        if (!in_array($type, array('special','trait','weapon','unit'), true)) { $type = 'special'; }
        $description = isset($input['description']) && is_scalar($input['description']) ? trim((string)$input['description']) : '';
        return array('type'=>$type, 'description'=>substr(strip_tags($description), 0, 5000));
    }
    public static function sanitise_rule_links($input, array $allowed): array {
        if (!is_array($input)) { return array(); }
        $result = array();
        foreach (array_slice($input, 0, 40) as $slug) {
            if (!is_scalar($slug)) { continue; }
            $slug = trim((string)$slug);
            if ($slug !== '' && in_array($slug, $allowed, true) && !in_array($slug, $result, true)) { $result[] = $slug; }
        }
        return $result;
    }
    public static function rule_save(): void {
        if (!current_user_can('manage_options')) { wp_die('Access denied.'); }
        check_admin_referer('ls_rule_save');
        global $wpdb;
        $id = isset($_POST['ls_id']) ? absint($_POST['ls_id']) : 0;
        $release = isset($_POST['ls_release']) ? absint($_POST['ls_release']) : 0;
        $title = isset($_POST['ls_title']) ? sanitize_text_field(wp_unslash($_POST['ls_title'])) : '';
        $slug = sanitize_title(isset($_POST['ls_slug']) ? wp_unslash($_POST['ls_slug']) : $title);
        $raw = isset($_POST['ls_rule']) && is_array($_POST['ls_rule']) ? wp_unslash($_POST['ls_rule']) : array();
        $notice = 'invalid';
        if ($id) {
            $record = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . LS_Schema::table('rules') . ' WHERE id = %d', $id));
            $release = $record ? (int)$record->release_id : 0;
        }
        if ($release && self::release_is_draft($release) && !self::release_has_armies($release) && $title !== '' && $slug !== '') {
            $values = array('title'=>$title, 'slug'=>$slug, 'data'=>wp_json_encode(self::sanitise_rule($raw)));
            if ($id) { $ok = $wpdb->update(LS_Schema::table('rules'), $values, array('id'=>$id,'release_id'=>$release), array('%s','%s','%s'), array('%d','%d')); }
            else { $values['release_id'] = $release; $ok = $wpdb->insert(LS_Schema::table('rules'), $values, array('%s','%s','%s','%d')); }
            $notice = $ok !== false ? 'updated' : 'invalid';
        }
        wp_safe_redirect(add_query_arg(array('page'=>'legion-strategium','ls_chamber'=>'rules','ls_armoury_notice'=>$notice), admin_url('admin.php'))); exit;
    }
    public static function rule_delete(): void {
        if (!current_user_can('manage_options')) { wp_die('Access denied.'); }
        check_admin_referer('ls_rule_delete');
        global $wpdb;
        $id = isset($_POST['ls_id']) ? absint($_POST['ls_id']) : 0;
        $record = $id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . LS_Schema::table('rules') . ' WHERE id = %d', $id)) : null;
        $notice = 'invalid';
        if ($record && self::release_is_draft((int)$record->release_id) && !self::release_has_armies((int)$record->release_id)) {
            // Keep referenced rules intact; unlink them from units and equipment first.
            $used = false;
            foreach (array('units','wargear') as $table) {
                foreach (self::rows($table, 'release_id = %d', array((int)$record->release_id)) as $item) {
                    $data = json_decode((string)$item->data, true);
                    if (is_array($data) && in_array($record->slug, $data['rule_links'] ?? array(), true)) { $used = true; break; }
                }
            }
            if (!$used) { $notice = $wpdb->delete(LS_Schema::table('rules'), array('id'=>$id), array('%d')) === 1 ? 'deleted' : 'invalid'; }
        }
        wp_safe_redirect(add_query_arg(array('page'=>'legion-strategium','ls_chamber'=>'rules','ls_armoury_notice'=>$notice), admin_url('admin.php'))); exit;
    }
    /** Edition publication is independent of the release lifecycle. */
    public static function edition_transition_allowed(string $status, string $target, bool $has_published_releases, bool $has_armies): bool {
        if ($target === 'published') { return $status === 'draft'; }
        if ($target === 'draft') { return $status === 'published' && !$has_published_releases && !$has_armies; }
        return false;
    }
    public static function transition_edition(): void {
        if (!current_user_can('manage_options')) { wp_die('Access denied.'); }
        check_admin_referer('ls_armoury_edition_transition');
        $id = isset($_POST['ls_id']) ? absint($_POST['ls_id']) : 0;
        $target = isset($_POST['ls_target']) && is_scalar($_POST['ls_target']) ? sanitize_key(wp_unslash((string)$_POST['ls_target'])) : '';
        $notice = 'edition_transition_blocked';
        if ($id > 0 && in_array($target, array('draft', 'published'), true)) {
            global $wpdb;
            $editions = LS_Schema::table('editions');
            $releases = LS_Schema::table('releases');
            $armies = LS_Schema::table('armies');
            $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . $editions . ' WHERE id = %d', $id));
            if (is_string($status)) {
                $has_published = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $releases . " WHERE edition_id = %d AND status = 'published'", $id)) > 0;
                $has_armies = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $armies . ' a INNER JOIN ' . $releases . ' r ON r.id = a.release_id WHERE r.edition_id = %d', $id)) > 0;
                if (self::edition_transition_allowed($status, $target, $has_published, $has_armies)) {
                    $updated = $wpdb->update($editions, array('status'=>$target), array('id'=>$id, 'status'=>$status), array('%s'), array('%d', '%s'));
                    $notice = $updated === 1 ? 'edition_transitioned' : 'edition_transition_blocked';
                }
            }
        }
        wp_safe_redirect(add_query_arg(array('page'=>'legion-strategium', 'ls_chamber'=>'editions', 'ls_armoury_notice'=>$notice), admin_url('admin.php')));
        exit;
    }
    public static function edition_transition_form(int $id, string $target, string $label): string {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_armoury_edition_transition"><input type="hidden" name="ls_id" value="' . $id . '"><input type="hidden" name="ls_target" value="' . esc_attr($target) . '">' . wp_nonce_field('ls_armoury_edition_transition', '_wpnonce', true, false) . '<button type="submit" class="button button-secondary">' . esc_html($label) . '</button></form>';
    }

    /** Explain publication prerequisites without changing the draft or its catalogue. */
    public static function publication_issue(int $factions, int $units, int $invalid_units): string {
        if ($factions < 1) { return 'publish_no_factions'; }
        if ($units < 1) { return 'publish_no_units'; }
        if ($invalid_units > 0) { return 'publish_invalid_units'; }
        return '';
    }

    /** Publication locks catalogue edits; safe lifecycle changes are handled separately. */
    public static function publish(): void {
        if (!current_user_can('manage_options')) { wp_die('Access denied.'); }
        check_admin_referer('ls_armoury_publish');
        $id = isset($_POST['ls_id']) ? absint($_POST['ls_id']) : 0;
        global $wpdb;
        $notice = 'publish_not_draft';
        if ($id && self::release_is_draft($id)) {
            $faction_count = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . LS_Schema::table('factions') . ' WHERE release_id = %d', $id));
            $unit_count = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . LS_Schema::table('units') . ' WHERE release_id = %d', $id));
            $invalid_units = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . LS_Schema::table('units') . ' u LEFT JOIN ' . LS_Schema::table('factions') . ' f ON u.faction_id = f.id AND u.release_id = f.release_id WHERE u.release_id = %d AND f.id IS NULL', $id));
            $notice = self::publication_issue($faction_count, $unit_count, $invalid_units);
            if ($notice === '') {
                $updated = $wpdb->update(LS_Schema::table('releases'), array('status'=>'published','published_at'=>current_time('mysql')), array('id'=>$id,'status'=>'draft'), array('%s','%s'), array('%d','%s'));
                $notice = $updated === 1 ? 'published' : 'publish_db_failed';
            }
        }
        $context = self::browse_context($_POST);
        wp_safe_redirect(add_query_arg(array_merge(array('page'=>'legion-strategium','ls_chamber'=>'releases','ls_armoury_notice'=>$notice), $context), admin_url('admin.php')));
        exit;
    }

    /** Preserve all used releases. Only an unused publication may return to draft. */
    public static function release_transition_allowed(int $id, string $target, string $status, bool $used): bool {
        if ($id <= 0) { return false; }
        if ($target === 'draft') { return $status === 'published' && !$used; }
        if ($target === 'retired') { return $status === 'published'; }
        if ($target === 'published') { return $status === 'retired'; }
        return false;
    }

    /** Transitions never modify army records, battle snapshots or catalogue content. */
    public static function transition_release_data(int $id, string $target): bool {
        if ($id <= 0 || !in_array($target, array('draft', 'retired', 'published'), true)) { return false; }
        global $wpdb;
        $table = LS_Schema::table('releases');
        $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . $table . ' WHERE id = %d', $id));
        if (!is_string($status)) { return false; }
        $used = self::release_has_armies($id);
        if (!self::release_transition_allowed($id, $target, $status, $used)) { return false; }
        // An army is the parent of each battle: preserving all armies preserves their battle links.
        $where = array('id'=>$id, 'status'=>$status);
        $values = array('status'=>$target);
        $formats = array('%s');
        if ($target === 'draft') { $values['published_at'] = null; $formats[] = '%s'; }
        return $wpdb->update($table, $values, $where, $formats, array('%d','%s')) === 1;
    }

    public static function transition_release(): void {
        if (!current_user_can('manage_options')) { wp_die('Access denied.'); }
        check_admin_referer('ls_armoury_release_transition');
        $id = isset($_POST['ls_id']) ? absint($_POST['ls_id']) : 0;
        $target = isset($_POST['ls_target']) && is_scalar($_POST['ls_target']) ? sanitize_key(wp_unslash((string)$_POST['ls_target'])) : '';
        $notice = self::transition_release_data($id, $target) ? 'release_transitioned' : 'release_transition_blocked';
        wp_safe_redirect(add_query_arg(array('page'=>'legion-strategium', 'ls_chamber'=>'releases', 'ls_armoury_notice'=>$notice), admin_url('admin.php')));
        exit;
    }

    /** A clone is a new independent draft. Never copy armies or battles. */
    public static function clone_release(): void {
        if (!current_user_can('manage_options')) { wp_die('Access denied.'); }
        check_admin_referer('ls_armoury_clone');
        $source = isset($_POST['ls_id']) ? absint($_POST['ls_id']) : 0;
        $version = isset($_POST['ls_version']) ? sanitize_text_field(wp_unslash($_POST['ls_version'])) : '';
        $notice = self::clone_release_data($source, $version) ? 'cloned' : 'clone_failed';
        $context = self::browse_context($_POST);
        wp_safe_redirect(add_query_arg(array_merge(array('page'=>'legion-strategium','ls_chamber'=>'releases','ls_armoury_notice'=>$notice), $context), admin_url('admin.php')));
        exit;
    }

    /** Count every catalogue row that would be removed, without touching another release. */
    public static function release_deletion_counts(int $id): array {
        global $wpdb;
        $counts = array();
        foreach (array('units', 'factions', 'wargear', 'rules', 'detachments') as $table) {
            $counts[$table] = (int)$wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . LS_Schema::table($table) . ' WHERE release_id = %d', $id
            ));
        }
        return $counts;
    }

    /** No permanent deletion of published releases or any release used by armies/battles. */
    public static function release_can_be_deleted(int $id): bool {
        global $wpdb;
        if ($id <= 0 || !self::release_is_draft($id) || self::release_has_armies($id)) { return false; }
        // An army may have been referenced by a battle; refuse deletion if any remains.
        $battles = (int)$wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . LS_Schema::table('battles') . ' b INNER JOIN ' . LS_Schema::table('armies') . ' a ON b.army_id = a.id WHERE a.release_id = %d', $id
        ));
        return $battles === 0;
    }

    /** Deletes only the requested unused draft. All SQL operations must succeed or roll back. */
    public static function delete_release_data(int $id, string $expected_version): bool {
        global $wpdb;
        if ($id <= 0 || $expected_version === '' || !self::release_can_be_deleted($id)) { return false; }
        $table = LS_Schema::table('releases');
        $release = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE id = %d', $id));
        if (!$release || $release->status !== 'draft' || !hash_equals((string)$release->version, $expected_version)) { return false; }
        if ($wpdb->query('START TRANSACTION') === false) { return false; }
        // Recheck under the transaction before modifying anything.
        if (!self::release_can_be_deleted($id)) { $wpdb->query('ROLLBACK'); return false; }
        $ok = true;
        foreach (array('units', 'factions', 'wargear', 'rules', 'detachments') as $name) {
            $result = $wpdb->delete(LS_Schema::table($name), array('release_id'=>$id), array('%d'));
            if ($result === false) { $ok = false; break; }
        }
        if ($ok) {
            $ok = $wpdb->delete($table, array('id'=>$id, 'status'=>'draft', 'version'=>$expected_version), array('%d','%s','%s')) === 1;
        }
        if (!$ok) { $wpdb->query('ROLLBACK'); return false; }
        if ($wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); return false; }
        return true;
    }

    public static function delete_release(): void {
        if (!current_user_can('manage_options')) { wp_die('Access denied.'); }
        check_admin_referer('ls_armoury_delete_release');
        $id = isset($_POST['ls_id']) ? absint($_POST['ls_id']) : 0;
        $version = isset($_POST['ls_confirm_version']) && is_string($_POST['ls_confirm_version'])
            ? sanitize_text_field(wp_unslash($_POST['ls_confirm_version'])) : '';
        $notice = self::delete_release_data($id, $version) ? 'release_deleted' : 'release_delete_failed';
        wp_safe_redirect(add_query_arg(array('page'=>'legion-strategium', 'ls_chamber'=>'releases', 'ls_armoury_notice'=>$notice), admin_url('admin.php')));
        exit;
    }

    /** Transactional copy of release-scoped catalogue records with remapped faction IDs. */
    public static function clone_release_data(int $source, string $version): bool {
        global $wpdb;
        $version = trim($version);
        if ($source <= 0 || $version === '' || strlen($version) > 80 || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $version)) { return false; }
        $release_table = LS_Schema::table('releases');
        $original = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $release_table . ' WHERE id = %d', $source));
        if (!$original || !in_array($original->status, array('draft', 'published'), true)) { return false; }
        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $release_table . ' WHERE edition_id = %d AND version = %s', (int)$original->edition_id, $version));
        if ($existing) { return false; }
        if ($wpdb->query('START TRANSACTION') === false) { return false; }
        $ok = $wpdb->insert($release_table, array('edition_id'=>(int)$original->edition_id,'version'=>$version,'status'=>'draft'), array('%d','%s','%s')) === 1;
        $new_id = $ok ? (int)$wpdb->insert_id : 0;
        $map = array();
        if ($ok) {
            foreach (self::rows('factions', 'release_id = %d', array($source)) as $row) {
                if ($wpdb->insert(LS_Schema::table('factions'), array('release_id'=>$new_id,'slug'=>$row->slug,'title'=>$row->title,'data'=>$row->data), array('%d','%s','%s','%s')) !== 1) { $ok = false; break; }
                $map[(int)$row->id] = (int)$wpdb->insert_id;
            }
        }
        if ($ok) {
            foreach (self::rows('units', 'release_id = %d', array($source)) as $row) {
                if (!isset($map[(int)$row->faction_id])) { $ok = false; break; }
                if ($wpdb->insert(LS_Schema::table('units'), array('release_id'=>$new_id,'faction_id'=>$map[(int)$row->faction_id],'slug'=>$row->slug,'title'=>$row->title,'data'=>$row->data), array('%d','%d','%s','%s','%s')) !== 1) { $ok = false; break; }
            }
        }
        foreach (array('detachments','wargear','rules') as $table) {
            if (!$ok) { break; }
            foreach (self::rows($table, 'release_id = %d', array($source)) as $row) {
                if ($wpdb->insert(LS_Schema::table($table), array('release_id'=>$new_id,'slug'=>$row->slug,'title'=>$row->title,'data'=>$row->data), array('%d','%s','%s','%s')) !== 1) { $ok = false; break; }
            }
        }
        if (!$ok) { $wpdb->query('ROLLBACK'); return false; }
        if ($wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); return false; }
        return true;
    }

    public static function rows(string $table, string $where = '', array $args = array()): array {
        global $wpdb;
        $sql = 'SELECT * FROM ' . LS_Schema::table($table) . ($where ? ' WHERE ' . $where : '') . ' ORDER BY id DESC';
        return (array)$wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql);
    }
    /** Bounded release-local unit prerequisites. Blank rows are ignored. */
    public static function sanitise_unit_prerequisites($input, string $own_slug = ''): array {
        if (!is_array($input)) { return array(); }
        $result = array();
        foreach (array_slice($input, 0, 8) as $row) {
            if (!is_array($row)) { continue; }
            $slug = isset($row['slug']) && is_scalar($row['slug']) ? strtolower(trim((string)$row['slug'])) : '';
            if ($slug === '') { continue; }
            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || $slug === $own_slug || isset($result[$slug])) { continue; }
            $minimum = filter_var($row['min'] ?? 1, FILTER_VALIDATE_INT);
            if ($minimum === false || $minimum < 1 || $minimum > 100) { continue; }
            $result[$slug] = array('slug'=>$slug, 'min'=>$minimum);
        }
        return array_values($result);
    }
    public static function save(): void {
        if (!current_user_can('manage_options')) { wp_die('Access denied.'); }
        check_admin_referer('ls_armoury_save');
        $kind = isset($_POST['ls_kind']) ? sanitize_key(wp_unslash($_POST['ls_kind'])) : '';
        $title = isset($_POST['ls_title']) ? sanitize_text_field(wp_unslash($_POST['ls_title'])) : '';
        $slug = sanitize_title(isset($_POST['ls_slug']) ? wp_unslash($_POST['ls_slug']) : $title);
        $release = isset($_POST['ls_release']) ? absint($_POST['ls_release']) : 0;
        $faction = isset($_POST['ls_faction']) ? absint($_POST['ls_faction']) : 0;
        $version = isset($_POST['ls_version']) ? sanitize_text_field(wp_unslash($_POST['ls_version'])) : '';
        $edition = isset($_POST['ls_edition']) ? absint($_POST['ls_edition']) : 0;
        $role = isset($_POST['ls_role']) ? sanitize_text_field(wp_unslash($_POST['ls_role'])) : '';
        $points = isset($_POST['ls_points']) ? filter_var(wp_unslash($_POST['ls_points']), FILTER_VALIDATE_INT) : false;
        $result = false;
        global $wpdb;
        if ($kind === 'edition' && $title && $slug) {
            $result = $wpdb->insert(LS_Schema::table('editions'), array('slug'=>$slug,'title'=>$title,'status'=>'draft','created_at'=>current_time('mysql')), array('%s','%s','%s','%s'));
        } elseif ($kind === 'release' && $edition && $version && self::exists('editions', $edition)) {
            $result = $wpdb->insert(LS_Schema::table('releases'), array('edition_id'=>$edition,'version'=>$version,'status'=>'draft'), array('%d','%s','%s'));
        } elseif ($kind === 'faction' && $release && $title && $slug && self::exists('releases', $release)) {
            $result = self::release_is_draft($release) ? $wpdb->insert(LS_Schema::table('factions'), array('release_id'=>$release,'slug'=>$slug,'title'=>$title,'data'=>'{}'), array('%d','%s','%s','%s')) : false;
        } elseif ($kind === 'wargear' && $release && $title && $slug && self::release_is_draft($release) && !self::release_has_armies($release)) {
            $raw = isset($_POST['ls_equipment']) && is_array($_POST['ls_equipment']) ? wp_unslash($_POST['ls_equipment']) : array();
            $result = $wpdb->insert(LS_Schema::table('wargear'), array('release_id'=>$release,'slug'=>$slug,'title'=>$title,'data'=>wp_json_encode(self::sanitise_equipment($raw))), array('%d','%s','%s','%s'));
        } elseif ($kind === 'unit' && $release && $faction && $title && $slug && $points !== false && $points >= 0 && $points <= 100000 && self::faction_in_release($faction, $release)) {
            $data = wp_json_encode(array('role'=>$role,'base_points'=>$points));
            $result = self::release_is_draft($release) ? $wpdb->insert(LS_Schema::table('units'), array('release_id'=>$release,'faction_id'=>$faction,'slug'=>$slug,'title'=>$title,'data'=>$data), array('%d','%d','%s','%s','%s')) : false;
        }
        wp_safe_redirect(add_query_arg(array('page'=>'legion-strategium','ls_chamber'=>self::chamber_for_kind($kind),'ls_armoury_notice'=>$result === 1 ? 'saved' : 'invalid'), admin_url('admin.php')));
        exit;
    }
    /** Manage draft records only. Published releases are never changed in place. */
    public static function manage(): void {
        if (!current_user_can('manage_options')) { wp_die('Access denied.'); }
        check_admin_referer('ls_armoury_manage');
        global $wpdb;
        $kind = isset($_POST['ls_kind']) ? sanitize_key(wp_unslash($_POST['ls_kind'])) : '';
        $operation = isset($_POST['ls_operation']) ? sanitize_key(wp_unslash($_POST['ls_operation'])) : '';
        $id = isset($_POST['ls_id']) ? absint($_POST['ls_id']) : 0;
        $notice = 'invalid';
        if ($id && in_array($kind, array('unit','faction','wargear'), true) && in_array($operation, array('edit','delete'), true)) {
            $table = $kind === 'unit' ? 'units' : ($kind === 'wargear' ? 'wargear' : 'factions');
            $record = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . LS_Schema::table($table) . ' WHERE id = %d', $id));
            if ($record && self::release_is_draft((int)$record->release_id)) {
                if ($operation === 'delete') {
                    // Deleting a faction with units would orphan them.
                    $has_units = $kind === 'faction' && (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . LS_Schema::table('units') . ' WHERE faction_id = %d', $id)) > 0;
                    if (!$has_units && !self::release_has_armies((int)$record->release_id)) {
                        $notice = $wpdb->delete(LS_Schema::table($table), array('id'=>$id), array('%d')) === 1 ? 'deleted' : 'invalid';
                    }
                } else {
                    $title = isset($_POST['ls_title']) ? sanitize_text_field(wp_unslash($_POST['ls_title'])) : '';
                    $slug = sanitize_title(isset($_POST['ls_slug']) ? wp_unslash($_POST['ls_slug']) : $title);
                    if ($title !== '' && $slug !== '') {
                        $values = array('title'=>$title,'slug'=>$slug);
                        $formats = array('%s','%s');
                        if ($kind === 'wargear') {
                            $raw = isset($_POST['ls_equipment']) && is_array($_POST['ls_equipment']) ? wp_unslash($_POST['ls_equipment']) : array();
                            $values['data'] = wp_json_encode(self::sanitise_equipment($raw));
                            if (isset($_POST['ls_rule_links_present'])) {
                                $existing = json_decode((string)$record->data, true);
                                $existing = is_array($existing) ? $existing : array();
                                $allowed_rules = array_map(static function ($r) { return (string)$r->slug; }, self::rows('rules', 'release_id = %d', array((int)$record->release_id)));
                                $new_data = self::sanitise_equipment($raw);
                                $new_data['rule_links'] = self::sanitise_rule_links(wp_unslash(isset($_POST['ls_rule_links']) ? $_POST['ls_rule_links'] : array()), $allowed_rules);
                                $values['data'] = wp_json_encode($new_data);
                            }
                            $formats[] = '%s';
                        }
                        if ($kind === 'unit') {
                            $faction = isset($_POST['ls_faction']) ? absint($_POST['ls_faction']) : 0;
                            $points = isset($_POST['ls_points']) ? filter_var(wp_unslash($_POST['ls_points']), FILTER_VALIDATE_INT) : false;
                            $role = isset($_POST['ls_role']) ? sanitize_text_field(wp_unslash($_POST['ls_role'])) : '';
                            if (!self::faction_in_release($faction, (int)$record->release_id) || $points === false || $points < 0 || $points > 100000) {
                                $values = array();
                            } else {
                                $data = json_decode((string)$record->data, true);
                                if (!is_array($data)) { $data = array(); }
                                $data['role'] = $role;
                                $data['base_points'] = $points;
                                if (isset($_POST['ls_unit_prerequisites_present'])) {
                                    $raw_requirements = isset($_POST['ls_unit_prerequisites']) && is_array($_POST['ls_unit_prerequisites']) ? wp_unslash($_POST['ls_unit_prerequisites']) : array();
                                    $requirements = self::sanitise_unit_prerequisites($raw_requirements, (string)$record->slug);
                                    // A prerequisite must refer to another unit in this release.
                                    $available = array_map(static function ($u) { return (string)$u->slug; }, self::rows('units', 'release_id = %d', array((int)$record->release_id)));
                                    foreach ($requirements as $requirement) {
                                        if (!in_array($requirement['slug'], $available, true)) { $values = array(); $notice = 'invalid'; break; }
                                    }
                                    if ($values) { $data['unit_prerequisites'] = $requirements; }
                                }

                                $profile_input = isset($_POST['ls_profile']) && is_array($_POST['ls_profile']) ? wp_unslash($_POST['ls_profile']) : array();
                                $data['profile'] = self::sanitise_profile($profile_input);
                                if (isset($_POST['ls_model_profiles_present'])) {
                                    $raw_models = is_array($_POST['ls_model_profiles']) ? wp_unslash($_POST['ls_model_profiles']) : array();
                                    $data['model_profiles'] = self::sanitise_model_profiles($raw_models);
                                }
                                if (isset($_POST['ls_composition'])) {
                                    $raw_composition = is_array($_POST['ls_composition']) ? wp_unslash($_POST['ls_composition']) : array();
                                    $data['composition'] = self::sanitise_composition($raw_composition);
                                }
                                if (isset($_POST['ls_equipment_links_present'])) {
                                    $equipment = self::rows('wargear', 'release_id = %d', array((int)$record->release_id));
                                    $slugs = array_map(static function ($e) { return (string)$e->slug; }, $equipment);
                                    $names = array_map(static function ($m) { return (string)$m['name']; }, $data['model_profiles'] ?? array());
                                    $raw_links = is_array($_POST['ls_equipment_links']) ? wp_unslash($_POST['ls_equipment_links']) : array();
                                    $data['equipment_links'] = self::sanitise_equipment_links($raw_links, $slugs, $names);
                                }
                                if (isset($_POST['ls_unit_options_present'])) {
                                    $equipment_for_options = self::rows('wargear', 'release_id = %d', array((int)$record->release_id));
                                    $option_slugs = array_map(static function ($e) { return (string)$e->slug; }, $equipment_for_options);
                                    $option_models = array_map(static function ($m) { return (string)$m['name']; }, $data['model_profiles'] ?? array());
                                    $raw_options = is_array($_POST['ls_unit_options']) ? wp_unslash($_POST['ls_unit_options']) : array();
                                    $option_error = self::unit_option_error($raw_options, $option_slugs, $option_models);
                                    if ($option_error !== '') {
                                        // Do not silently erase invalid option rows or overwrite existing data.
                                        $values = array();
                                        $notice = 'option_invalid';
                                    } else {
                                        $data['unit_options'] = self::sanitise_unit_options($raw_options, $option_slugs, $option_models);
                                    }
                                }
                                if (isset($_POST['ls_rule_links_present'])) {
                                    $allowed_rules = array_map(static function ($r) { return (string)$r->slug; }, self::rows('rules', 'release_id = %d', array((int)$record->release_id)));
                                    $data['rule_links'] = self::sanitise_rule_links(wp_unslash(isset($_POST['ls_rule_links']) ? $_POST['ls_rule_links'] : array()), $allowed_rules);
                                }
                                if ($values) {
                                    $values['faction_id'] = $faction;
                                    $values['data'] = wp_json_encode($data);
                                }
                                $formats = array('%s','%s','%d','%s');
                            }
                        }
                        if ($values && !self::release_has_armies((int)$record->release_id)) {
                            $updated = $wpdb->update(LS_Schema::table($table), $values, array('id'=>$id), $formats, array('%d'));
                            $notice = $updated !== false ? 'updated' : 'invalid';
                        }
                    }
                }
            }
        }
        $context = self::browse_context($_POST);
        if ($kind === 'unit' && $operation === 'edit' && $notice === 'updated') { $context['ls_edit_unit'] = $id; }
        wp_safe_redirect(add_query_arg(array_merge(array('page'=>'legion-strategium','ls_chamber'=>self::chamber_for_kind($kind),'ls_armoury_notice'=>$notice), $context), admin_url('admin.php')));
        exit;
    }
    public static function release_is_draft(int $id): bool {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . LS_Schema::table('releases') . ' WHERE id = %d', $id)) === 'draft';
    }
    public static function release_has_armies(int $id): bool {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . LS_Schema::table('armies') . ' WHERE release_id = %d', $id)) > 0;
    }
    private static function rule_link_fields(object $record, array $data): string {
        $rules = self::rows('rules', 'release_id = %d', array((int)$record->release_id));
        $selected = isset($data['rule_links']) && is_array($data['rule_links']) ? $data['rule_links'] : array();
        $html = '<details class="ls-sheet-panel"><summary>Linked rules and traits</summary><p>Choose reusable rules from this release. Hold Ctrl/Cmd to select several; leave empty to unlink all.</p><select multiple size="6" name="ls_rule_links[]">';
        foreach ($rules as $rule) { $html .= '<option value="' . esc_attr($rule->slug) . '" ' . selected(in_array($rule->slug, $selected, true), true, false) . '>' . esc_html($rule->title) . '</option>'; }
        return $html . '</select></details><input type="hidden" name="ls_rule_links_present" value="1">';
    }
    private static function manage_form(string $kind, object $record, array $factions): string {
        $draft = self::release_is_draft((int)$record->release_id);
        if (!$draft) { return '<em>Published: locked</em>'; }
        $data = json_decode((string)($record->data ?? ''), true);
        $data = is_array($data) ? $data : array();
        $base = '<input type="hidden" name="action" value="ls_armoury_manage"><input type="hidden" name="ls_kind" value="' . esc_attr($kind) . '"><input type="hidden" name="ls_id" value="' . (int)$record->id . '">' . wp_nonce_field('ls_armoury_manage', '_wpnonce', true, false);
        $form = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . $base . '<input type="hidden" name="ls_operation" value="edit">';
        $form .= '<label>Name <input required name="ls_title" value="' . esc_attr($record->title) . '"></label> <label>Slug <input required name="ls_slug" value="' . esc_attr($record->slug) . '"></label> ';
        if ($kind === 'unit') {
            $form .= '<label>Faction <select name="ls_faction">';
            foreach ($factions as $faction) {
                if ((int)$faction->release_id !== (int)$record->release_id) { continue; }
                $form .= '<option value="' . (int)$faction->id . '" ' . selected((int)$record->faction_id, (int)$faction->id, false) . '>' . esc_html($faction->title) . '</option>';
            }
            $form .= '</select></label> <label>Role <input name="ls_role" value="' . esc_attr((string)($data['role'] ?? '')) . '"></label> <label>Points <input type="number" min="0" max="100000" required name="ls_points" value="' . esc_attr((string)($data['base_points'] ?? 0)) . '"></label> ';
        }
        if ($kind === 'wargear') {
            $form .= '<div class="ls-profile-grid"><label>Type <select name="ls_equipment[type]">';
            foreach (array('equipment'=>'Equipment','ranged'=>'Ranged weapon','melee'=>'Melee weapon') as $value=>$label) { $form .= '<option value="' . esc_attr($value) . '" ' . selected($data['type'] ?? 'equipment', $value, false) . '>' . esc_html($label) . '</option>'; }
            $form .= '</select></label>';
            foreach (array('range'=>'Range','strength'=>'Strength','ap'=>'AP','damage'=>'Damage','attacks'=>'Attacks','initiative_modifier'=>'Initiative Modifier','special'=>'Special') as $key=>$label) { $form .= '<label>' . esc_html($label) . '<input maxlength="80" name="ls_equipment[' . esc_attr($key) . ']" value="' . esc_attr((string)($data['profile'][$key] ?? '')) . '"></label>'; }
            $form .= '<label>Legacy rule names (comma-separated) <input name="ls_equipment[rules]" value="' . esc_attr(implode(', ', $data['rules'] ?? array())) . '"></label></div>';
            $form .= self::rule_link_fields($record, $data);
        }
        if ($kind === 'unit') {
            // Panels remain visible without JavaScript. The enhancement switches tabs client-side.
            $form = str_replace('<form method="post"', '<form class="ls-unit-workbench" method="post"', $form);
            $form .= '<nav class="ls-workbench-tabs" aria-label="Datasheet editor sections">';
            foreach (array('overview'=>'Overview', 'models'=>'Model Profiles', 'composition'=>'Composition', 'wargear'=>'Wargear', 'upgrades'=>'Upgrades', 'rules'=>'Special Rules') as $key=>$label) {
                $form .= '<button type="button" class="ls-workbench-tab" data-ls-tab="' . esc_attr($key) . '">' . esc_html($label) . '</button>';
            }
            $form .= '</nav><div class="ls-workbench-panel" data-ls-panel="overview"><h3>Unit overview</h3><p class="description">Edit the unit name, faction, role and base points above.</p>';
            $form .= '<div class="ls-profile-fields"><strong>Characteristics (legacy profile)</strong><div class="ls-profile-grid">';
            $profile = isset($data['profile']) && is_array($data['profile']) ? $data['profile'] : array();
            foreach (self::profile_fields() as $key => $label) {
                $form .= '<label>' . esc_html($label) . '<input maxlength="32" name="ls_profile[' . esc_attr($key) . ']" value="' . esc_attr((string)($profile[$key] ?? '')) . '" placeholder="—"></label>';
            }
            $form .= '</div></div><div class="ls-profile-fields"><strong>Unit prerequisites</strong><p class="description">Optional: require another unit from this draft release before this unit is considered compliant. Use its exact slug. Requirements are advisory in the army builder.</p><input type="hidden" name="ls_unit_prerequisites_present" value="1"><div class="ls-profile-grid">';
            $prerequisites = isset($data['unit_prerequisites']) && is_array($data['unit_prerequisites']) ? $data['unit_prerequisites'] : array();
            for ($i = 0; $i < 8; $i++) {
                $requirement = $prerequisites[$i] ?? array();
                $form .= '<label>Required unit slug <input maxlength="100" name="ls_unit_prerequisites[' . $i . '][slug]" value="' . esc_attr((string)($requirement['slug'] ?? '')) . '" placeholder="e.g. required-unit"></label><label>Minimum selections <input type="number" min="1" max="100" name="ls_unit_prerequisites[' . $i . '][min]" value="' . esc_attr((string)($requirement['min'] ?? 1)) . '"></label>';
            }
            $form .= '</div></div></div><section class="ls-workbench-panel" data-ls-panel="rules"><h3>Special rules and traits</h3>';
            $form .= self::rule_link_fields($record, $data);
            $form .= '</section><section class="ls-workbench-panel" data-ls-panel="models"><h3>Model profiles</h3>';
            $models = isset($data['model_profiles']) && is_array($data['model_profiles']) ? $data['model_profiles'] : array();
            if (!$models) { $models = array(array('name'=>(string)$record->title, 'characteristics'=>$profile)); }
            $form .= '<p class="description">Each row represents a model type. Blank names are ignored. Up to 20 model profiles per unit.</p>';
            $form .= '<input type="hidden" name="ls_model_profiles_present" value="1"><div class="ls-dynamic-list" data-ls-list="models" data-ls-limit="20">';
            for ($i=0; $i<max(1, count($models)); $i++) {
                if ($i >= 20) { break; }
                $model = $models[$i] ?? array();
                $stats = isset($model['characteristics']) && is_array($model['characteristics']) ? $model['characteristics'] : array();
                $form .= '<fieldset class="ls-model-profile" data-ls-row><legend>Model ' . ($i+1) . '</legend><button type="button" class="button ls-remove-row" data-ls-remove>Remove model</button><label>Model name <input maxlength="100" name="ls_model_profiles[' . $i . '][name]" value="' . esc_attr((string)($model['name'] ?? '')) . '"></label><div class="ls-profile-grid">';
                foreach (self::datasheet_fields() as $key=>$label) {
                    $form .= '<label>' . esc_html($label) . '<input maxlength="32" name="ls_model_profiles[' . $i . '][' . esc_attr($key) . ']" value="' . esc_attr((string)($stats[$key] ?? '')) . '"></label>';
                }
                $form .= '</div></fieldset>';
            }
            $form .= '</div><button type="button" class="button" data-ls-add="models">Add model profile</button>';
            $form .= '</section><section class="ls-workbench-panel" data-ls-panel="composition"><h3>Unit composition</h3>';
            $composition = isset($data['composition']) && is_array($data['composition']) ? $data['composition'] : array();
            $form .= '<div class="ls-profile-grid">';
            foreach (array('minimum_models'=>'Minimum models','maximum_models'=>'Maximum models','starting_models'=>'Starting models','additional_model_points'=>'Additional model points') as $key=>$label) {
                $form .= '<label>' . esc_html($label) . '<input type="number" min="0" max="100000" name="ls_composition[' . esc_attr($key) . ']" value="' . esc_attr(isset($composition[$key]) ? (string)$composition[$key] : '') . '"></label>';
            }
            $form .= '</div></section><section class="ls-workbench-panel" data-ls-panel="wargear"><h3>Wargear and weapon assignments</h3><p>Choose reusable equipment from this ruleset. Assign to the whole unit or one model profile.</p>';
            $equipment = self::rows('wargear', 'release_id = %d', array((int)$record->release_id));
            $links = isset($data['equipment_links']) && is_array($data['equipment_links']) ? $data['equipment_links'] : array();
            $form .= '<input type="hidden" name="ls_equipment_links_present" value="1"><div class="ls-dynamic-list" data-ls-list="wargear" data-ls-limit="40">';
            for ($i=0; $i<max(1, min(40, count($links))); $i++) {
                $link = $links[$i] ?? array();
                $form .= '<p data-ls-row><label>Wargear <select name="ls_equipment_links[' . $i . '][slug]"><option value="">— None —</option>';
                foreach ($equipment as $item) { $form .= '<option value="' . esc_attr($item->slug) . '" ' . selected($link['slug'] ?? '', $item->slug, false) . '>' . esc_html($item->title) . '</option>'; }
                $form .= '</select></label> <label>Assigned to <select name="ls_equipment_links[' . $i . '][model]"><option value="">Whole unit</option>';
                foreach ($models as $model) { $name = (string)($model['name'] ?? ''); if ($name !== '') { $form .= '<option value="' . esc_attr($name) . '" ' . selected($link['model'] ?? '', $name, false) . '>' . esc_html($name) . '</option>'; } }
                $form .= '</select></label> <button type="button" class="button ls-remove-row" data-ls-remove>Remove assignment</button></p>';
            }
            $form .= '</div><button type="button" class="button" data-ls-add="wargear">Add wargear assignment</button>';
            $form .= '</section><section class="ls-workbench-panel" data-ls-panel="upgrades"><h3>Upgrade options and points</h3><p class="description"><strong>Every upgrade needs a Name.</strong> For an exchange, choose Exchange equipment and select both weapons. Equipment choices show only records from this unit’s ruleset release (ID ' . (int)$record->release_id . '). Blank unused rows are ignored; maximum 30 options.</p>';
            $unit_options = isset($data['unit_options']) && is_array($data['unit_options']) ? $data['unit_options'] : array();
            $form .= '<input type="hidden" name="ls_unit_options_present" value="1"><div class="ls-dynamic-list" data-ls-list="upgrades" data-ls-limit="30">';
            for ($i=0; $i<max(1, min(30, count($unit_options))); $i++) {
                $opt = $unit_options[$i] ?? array();
                $prefix = 'ls_unit_options[' . $i . ']';
                $form .= '<fieldset class="ls-model-profile" data-ls-row><legend>Option ' . ($i+1) . '</legend><button type="button" class="button ls-remove-row" data-ls-remove>Remove option</button><div class="ls-profile-grid">';
                $form .= '<label>Name <input maxlength="100" name="' . $prefix . '[name]" value="' . esc_attr((string)($opt['name'] ?? '')) . '"></label>';
                $form .= '<label>Type <select name="' . $prefix . '[type]">';
                foreach (array('add'=>'Add equipment','replace'=>'Exchange equipment','model'=>'Additional model') as $value=>$label) { $form .= '<option value="' . esc_attr($value) . '" ' . selected($opt['type'] ?? 'add', $value, false) . '>' . esc_html($label) . '</option>'; }
                $form .= '</select></label><label>Eligible model <select name="' . $prefix . '[model]"><option value="">Whole unit / any model</option>';
                foreach ($models as $model) { $name = (string)($model['name'] ?? ''); if ($name !== '') { $form .= '<option value="' . esc_attr($name) . '" ' . selected($opt['model'] ?? '', $name, false) . '>' . esc_html($name) . '</option>'; } }
                $form .= '</select></label>';
                foreach (array('from'=>'Replace equipment','to'=>'With / add equipment') as $key=>$label) {
                    $form .= '<label>' . esc_html($label) . ' <select name="' . $prefix . '[' . $key . ']"><option value="">— None —</option>';
                    foreach ($equipment as $item) { $form .= '<option value="' . esc_attr($item->slug) . '" ' . selected($opt[$key] ?? '', $item->slug, false) . '>' . esc_html($item->title) . '</option>'; }
                    $form .= '</select></label>';
                }
                foreach (array('points'=>'Points adjustment','min'=>'Minimum selections','max'=>'Maximum selections') as $key=>$label) {
                    $default = $key === 'max' ? '1' : '0';
                    $form .= '<label>' . esc_html($label) . ' <input type="number" min="' . ($key === 'points' ? '-100000' : ($key === 'max' ? '1' : '0')) . '" max="' . ($key === 'points' ? '100000' : '100') . '" name="' . $prefix . '[' . $key . ']" value="' . esc_attr((string)($opt[$key] ?? $default)) . '"></label>';
                }
                $form .= '</div></fieldset>';
            }
            $form .= '</div><button type="button" class="button" data-ls-add="upgrades">Add upgrade option</button>';
            $form .= '</section>';
        }
        $form .= '<button class="button button-primary" type="submit">Save changes</button></form>';
        $form .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(&quot;Permanently delete this draft record?&quot;);">' . $base . '<input type="hidden" name="ls_operation" value="delete"><button type="submit" class="button button-link-delete">Delete draft ' . esc_html($kind) . '</button></form>';
        return $form;
    }
    public static function exists(string $table, int $id): bool {
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . LS_Schema::table($table) . ' WHERE id = %d', $id));
    }
    public static function faction_in_release(int $faction, int $release): bool {
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . LS_Schema::table('factions') . ' WHERE id = %d AND release_id = %d', $faction, $release));
    }
    private static function options(array $rows, string $label = 'title'): string {
        $html = '';
        foreach ($rows as $row) { $html .= '<option value="' . esc_attr((string)$row->id) . '">' . esc_html($row->$label) . ' (#' . (int)$row->id . ')</option>'; }
        return $html;
    }
    private static function form(string $kind, string $fields): string {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_armoury_save"><input type="hidden" name="ls_kind" value="' . esc_attr($kind) . '">' . wp_nonce_field('ls_armoury_save', '_wpnonce', true, false) . $fields . '<p><button class="button button-primary">Add ' . esc_html(ucfirst($kind)) . '</button></p></form>';
    }
    /** Whitelist browsing parameters so editor redirects cannot carry arbitrary URLs. */
    public static function browse_context(array $source): array {
        $result = array();
        foreach (array('ls_edition','ls_release','ls_faction','ls_page') as $key) {
            if (isset($source[$key])) { $result[$key] = absint($source[$key]); }
        }
        if (isset($source['ls_search'])) {
            $result['ls_search'] = substr(sanitize_text_field(wp_unslash($source['ls_search'])), 0, 100);
        }
        return $result;
    }
    private static function filter_options(array $rows, string $label, int $selected, string $empty): string {
        $html = '<option value="0">' . esc_html($empty) . '</option>';
        foreach ($rows as $row) {
            $html .= '<option value="' . (int)$row->id . '" ' . selected($selected, (int)$row->id, false) . '>' . esc_html((string)$row->$label) . '</option>';
        }
        return $html;
    }
    private static function browse_url(array $context): string {
        return add_query_arg(array_merge(array('page'=>'legion-strategium', 'ls_chamber'=>'units'), $context), admin_url('admin.php'));
    }
    /** Allowlisted server-side chamber routing; never trust arbitrary query values. */
    public static function admin_chamber(array $source): string {
        $value = isset($source['ls_chamber']) && is_string($source['ls_chamber']) ? $source['ls_chamber'] : 'units';
        return in_array($value, array('editions', 'releases', 'factions', 'units', 'wargear', 'rules', 'detachments'), true) ? $value : 'units';
    }

    public static function chamber_for_kind(string $kind): string {
        return array('edition'=>'editions', 'release'=>'releases', 'faction'=>'factions', 'unit'=>'units', 'wargear'=>'wargear')[$kind] ?? 'units';
    }

    /** Release-aware index matching, shared by the wargear and rules chambers. */
    public static function index_matches(array $records, int $release, string $search): array {
        $search = trim($search);
        return array_values(array_filter($records, static function ($record) use ($release, $search) {
            if ($release > 0 && (int)$record->release_id !== $release) { return false; }
            return $search === '' || stripos((string)$record->title, $search) !== false || stripos((string)$record->slug, $search) !== false;
        }));
    }

    /** Clamp an index page and return its bounded records. */
    public static function index_page(array $records, int $page, int $per_page = 20): array {
        $per_page = max(1, min(100, $per_page));
        $pages = max(1, (int)ceil(count($records) / $per_page));
        $page = max(1, min($page, $pages));
        return array('rows'=>array_slice($records, ($page - 1) * $per_page, $per_page), 'page'=>$page, 'pages'=>$pages, 'total'=>count($records));
    }

    private static function index_filters(string $chamber, array $releases, int $release, string $search): void {
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="ls-catalogue-filters">';
        echo '<input type="hidden" name="page" value="legion-strategium"><input type="hidden" name="ls_chamber" value="' . esc_attr($chamber) . '">';
        echo '<label>Release <select name="ls_release">' . self::filter_options($releases, 'version', $release, 'All releases') . '</select></label> ';
        echo '<label>Search <input type="search" name="ls_search" value="' . esc_attr($search) . '" placeholder="Name or slug"></label> ';
        echo '<button class="button button-primary">Find records</button> <a class="button" href="' . esc_url(add_query_arg(array('page'=>'legion-strategium','ls_chamber'=>$chamber), admin_url('admin.php'))) . '">Clear</a></form>';
    }

    private static function index_pagination(string $chamber, array $index, int $release, string $search): void {
        if ($index['pages'] <= 1) { return; }
        echo '<nav class="ls-pagination" aria-label="' . esc_attr(ucfirst($chamber)) . ' pages">';
        for ($i = 1; $i <= $index['pages']; $i++) {
            $url = add_query_arg(array('page'=>'legion-strategium','ls_chamber'=>$chamber,'ls_release'=>$release,'ls_search'=>$search,'ls_page'=>$i), admin_url('admin.php'));
            echo '<a class="button ' . ($i === $index['page'] ? 'button-primary' : '') . '" href="' . esc_url($url) . '">' . $i . '</a> ';
        }
        echo '</nav>';
    }

    public static function release_transition_form(int $id, string $target, string $label, string $confirmation): string {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(' . esc_attr(wp_json_encode($confirmation)) . ')"><input type="hidden" name="action" value="ls_armoury_release_transition"><input type="hidden" name="ls_id" value="' . $id . '"><input type="hidden" name="ls_target" value="' . esc_attr($target) . '">' . wp_nonce_field('ls_armoury_release_transition', '_wpnonce', true, false) . '<button class="button" type="submit">' . esc_html($label) . '</button></form>';
    }

    /** Structured detachment slot definitions. No edition-specific role assumptions. */
    public static function sanitise_detachment_slots($input): array {
        if (!is_array($input)) { return array(); }
        $slots = array();
        foreach (array_slice($input, 0, 12) as $row) {
            if (!is_array($row)) { continue; }
            $role = isset($row['role']) && is_scalar($row['role']) ? trim(sanitize_text_field((string)$row['role'])) : '';
            if ($role === '') { continue; }
            $minimum = filter_var($row['min'] ?? null, FILTER_VALIDATE_INT);
            $maximum = filter_var($row['max'] ?? null, FILTER_VALIDATE_INT);
            if (strlen($role) > 80 || $minimum === false || $maximum === false || $minimum < 0 || $maximum < $minimum || $maximum > 100) { return array(); }
            $slots[] = array('role'=>$role, 'min'=>$minimum, 'max'=>$maximum);
        }
        return $slots;
    }
    /** Only an unused draft release may have detachment definitions changed. */
    public static function detachment_editable(int $release_id): bool {
        return $release_id > 0 && self::release_is_draft($release_id) && !self::release_has_armies($release_id);
    }
    public static function save_detachment(): void {
        if (!current_user_can('manage_options')) { wp_die('Access denied.'); }
        check_admin_referer('ls_detachment_save');
        global $wpdb;
        $id = isset($_POST['ls_detachment_id']) ? absint($_POST['ls_detachment_id']) : 0;
        $release = isset($_POST['ls_release']) ? absint($_POST['ls_release']) : 0;
        $title = isset($_POST['ls_title']) ? sanitize_text_field(wp_unslash((string)$_POST['ls_title'])) : '';
        $slug = isset($_POST['ls_slug']) ? sanitize_title(wp_unslash((string)$_POST['ls_slug'])) : '';
        $raw = isset($_POST['ls_slots']) && is_array($_POST['ls_slots']) ? wp_unslash($_POST['ls_slots']) : array();
        $slots = self::sanitise_detachment_slots($raw);
        $has_slot = false;
        foreach ($raw as $row) { if (is_array($row) && !empty(trim((string)($row['role'] ?? '')))) { $has_slot = true; break; } }
        $result = false;
        if (self::detachment_editable($release) && $title !== '' && $slug !== '' && strlen($title) <= 190 && (!$has_slot || $slots)) {
            $record = $id ? $wpdb->get_row($wpdb->prepare('SELECT id, release_id FROM ' . LS_Schema::table('detachments') . ' WHERE id = %d', $id)) : null;
            if (!$id || ($record && (int)$record->release_id === $release)) {
                $values = array('slug'=>$slug, 'title'=>$title, 'data'=>wp_json_encode(array('slots'=>$slots)));
                if ($id) { $result = $wpdb->update(LS_Schema::table('detachments'), $values, array('id'=>$id,'release_id'=>$release), array('%s','%s','%s'), array('%d','%d')); }
                else { $values['release_id'] = $release; $result = $wpdb->insert(LS_Schema::table('detachments'), $values, array('%s','%s','%s','%d')); }
            }
        }
        wp_safe_redirect(add_query_arg(array('page'=>'legion-strategium','ls_chamber'=>'detachments','ls_armoury_notice'=>$result !== false ? 'detachment_saved' : 'detachment_invalid'), admin_url('admin.php')));
        exit;
    }
    private static function detachment_fields(int $release, string $title, string $slug, array $slots, int $id = 0): string {
        $out = '<form class="ls-detachment-editor" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_detachment_save"><input type="hidden" name="ls_release" value="' . $release . '"><input type="hidden" name="ls_detachment_id" value="' . $id . '">' . wp_nonce_field('ls_detachment_save', '_wpnonce', true, false);
        $out .= '<div class="ls-detachment-identity"><label>Detachment name <input name="ls_title" maxlength="190" required value="' . esc_attr($title) . '"></label><label>Slug <input name="ls_slug" maxlength="100" required value="' . esc_attr($slug) . '"></label></div><p class="description">Define role slots for this rules release. Leave unused rows blank. Slot enforcement will arrive in a later validation phase.</p><div class="ls-detachment-slots"><div class="ls-detachment-slot-heading"><strong>Battlefield role</strong><strong>Minimum</strong><strong>Maximum</strong></div>';
        for ($i = 0; $i < max(4, min(12, count($slots) + 1)); $i++) {
            $slot = $slots[$i] ?? array();
            $out .= '<div class="ls-detachment-slot"><label><span>Role</span><input name="ls_slots[' . $i . '][role]" maxlength="80" placeholder="e.g. a role from this release" value="' . esc_attr((string)($slot['role'] ?? '')) . '"></label><label><span>Minimum</span><input type="number" min="0" max="100" name="ls_slots[' . $i . '][min]" value="' . (int)($slot['min'] ?? 0) . '"></label><label><span>Maximum</span><input type="number" min="0" max="100" name="ls_slots[' . $i . '][max]" value="' . (int)($slot['max'] ?? 1) . '"></label></div>';
        }
        $out .= '</div><button class="button button-primary" type="submit">' . ($id ? 'Save detachment' : 'Create detachment') . '</button></form>';
        return $out;
    }
    private static function detachment_chamber(array $releases): void {
        echo '<h2>Detachment definitions</h2><p>Author release-specific detachment types and role slots. Published releases are read-only; clone to draft for FAQ changes. Slot validation is not yet enforced.</p>';
        $records = self::rows('detachments');
        foreach ($releases as $release) {
            $release_id = (int)$release->id;
            $editable = self::detachment_editable($release_id);
            echo '<section class="ls-detachment-release"><h3>' . esc_html($release->version) . ' <small>— ' . esc_html($release->status) . '</small></h3>';
            if ($editable) { echo '<details class="ls-detachment-new"><summary>Add detachment definition</summary>' . self::detachment_fields($release_id, '', '', array()) . '</details>'; }
            foreach ($records as $record) {
                if ((int)$record->release_id !== $release_id) { continue; }
                $data = json_decode((string)$record->data, true);
                $slots = is_array($data) && isset($data['slots']) && is_array($data['slots']) ? $data['slots'] : array();
                echo '<details class="ls-detachment-existing"><summary>' . esc_html($record->title) . ' (' . esc_html($record->slug) . ')</summary>';
                if ($editable) { echo self::detachment_fields($release_id, (string)$record->title, (string)$record->slug, $slots, (int)$record->id); }
                else { echo '<p>Read-only definition; clone this release to edit.</p>'; }
                if ($slots) { echo '<ul>'; foreach ($slots as $slot) { echo '<li>' . esc_html((string)($slot['role'] ?? '')) . ': ' . (int)($slot['min'] ?? 0) . '–' . (int)($slot['max'] ?? 0) . '</li>'; } echo '</ul>'; }
                echo '</details>';
            }
            echo '</section>';
        }
    }

    public static function admin(): void {
        if (!current_user_can('manage_options')) { wp_die('Access denied.'); }
        $editions = self::rows('editions'); $releases = self::rows('releases'); $factions = self::rows('factions'); $units = self::rows('units');
        echo '<div class="wrap ls-armoury-admin"><h1>The Armoury Archives</h1><p>Draft catalogue. Use sample or authorised data only. Published releases are locked.</p>';
        if (isset($_GET['ls_armoury_notice'])) { echo '<div class="notice notice-info"><p>' . (array('edition_transitioned'=>'Edition publication status updated.','edition_transition_blocked'=>'Edition status unchanged. An edition with published releases or existing armies cannot return to draft.','detachment_saved'=>'Detachment definition saved.','detachment_invalid'=>'Detachment not saved. Check the draft release, slug and slot limits.','saved'=>'Catalogue record added.','updated'=>'Draft record updated.','deleted'=>'Draft record deleted.','publish_no_factions'=>'Release not published: this draft has no factions. Check that its catalogue was copied correctly.', 'publish_no_units'=>'Release not published: this draft has no units. Add or restore a unit before publishing.', 'publish_invalid_units'=>'Release not published: one or more units refer to a missing or different-release faction. Correct those links before publishing.', 'publish_db_failed'=>'Release not published: the database did not update the release status. Check the WordPress database error log.', 'publish_not_draft'=>'Release not published: the selected release is not an editable draft.', 'published'=>'Ruleset published and locked.','release_transitioned'=>'Release status updated; existing army and battle records were preserved.','release_transition_blocked'=>'Release status unchanged. Used releases cannot return to draft; clone a new draft for FAQ corrections.','cloned'=>'New independent draft release created.','release_deleted'=>'Unused draft release and its catalogue records deleted.','release_delete_failed'=>'Release not deleted. Confirm its exact version and ensure it is a draft with no dependent armies or battles.','clone_failed'=>'Could not clone release. Check version uniqueness and catalogue integrity.','option_invalid'=>'Upgrade option not saved: check that every used row has a name, correct option type, valid model and equipment from this release, and valid points/limits. Other changes were not saved.') [sanitize_key(wp_unslash($_GET['ls_armoury_notice']))] ?? 'Could not change record: check relationships, duplicate slugs, or whether the release is locked or in use.') . '</p></div>'; }
        $chambers = array('editions'=>'Editions', 'releases'=>'Releases', 'factions'=>'Factions', 'units'=>'Units', 'wargear'=>'Weapons & Wargear', 'rules'=>'Rules & Traits', 'detachments'=>'Detachments');
        $chamber = self::admin_chamber($_GET);
        echo '<nav class="nav-tab-wrapper ls-chambers" aria-label="Armoury chambers">';
        foreach ($chambers as $key => $label) {
            $url = add_query_arg(array('page'=>'legion-strategium', 'ls_chamber'=>$key), admin_url('admin.php'));
            echo '<a class="nav-tab ' . ($chamber === $key ? 'nav-tab-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav><div class="ls-chamber-content">';
        if ($chamber === 'detachments') { self::detachment_chamber($releases); }
        elseif ($chamber === 'editions') {
        echo '<h2>1. Editions</h2>' . self::form('edition', '<p><label>Title <input required name="ls_title" placeholder="Horus Heresy Third Edition"></label> <label>Slug <input name="ls_slug" placeholder="hh-3"></label></p>');
        echo '<h2>Existing editions</h2><p class="description">Publish an edition to make its published ruleset releases available to commanders. An edition with published releases or armies cannot return to draft.</p>';
        if (!$editions) { echo '<p>No editions have been created yet.</p>'; }
        foreach ($editions as $edition) {
            $edition_id = (int)$edition->id;
            $has_published = false;
            foreach ($releases as $release) { if ((int)$release->edition_id === $edition_id && $release->status === 'published') { $has_published = true; break; } }
            echo '<div class="ls-release-row"><strong>' . esc_html($edition->title) . '</strong> <span>' . esc_html(ucfirst((string)$edition->status)) . '</span>';
            if ($edition->status === 'draft') { echo self::edition_transition_form($edition_id, 'published', 'Publish edition'); }
            if ($edition->status === 'published') {
                echo '<span class="description">Published editions can be selected for army creation when they have a published release.</span>';
                if (!$has_published) {
                    global $wpdb;
                    $army_count = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . LS_Schema::table('armies') . ' a INNER JOIN ' . LS_Schema::table('releases') . ' r ON r.id = a.release_id WHERE r.edition_id = %d', $edition_id));
                    if ($army_count === 0) { echo self::edition_transition_form($edition_id, 'draft', 'Return unused edition to draft'); }
                }
            }
            if ($edition->status === 'draft' && $has_published) { echo '<p class="notice notice-warning">This edition has a published ruleset release, but armies cannot use it until the edition is published.</p>'; }
            echo '</div>';
        }

        }
        if ($chamber === 'releases') {
        echo '<h2>2. Ruleset Releases</h2>' . self::form('release', '<p><label>Edition <select name="ls_edition" required>' . self::options($editions) . '</select></label> <label>Version <input required name="ls_version" placeholder="2026-draft-1"></label></p>');
        }
        if ($chamber === 'factions') {
        echo '<h2>3. Factions</h2>' . self::form('faction', '<p><label>Release <select name="ls_release" required>' . self::options($releases, 'version') . '</select></label> <label>Name <input required name="ls_title"></label> <label>Slug <input name="ls_slug"></label></p>');
        }
        if ($chamber === 'units') {
        echo '<h2>4. Units</h2><p>Choose a faction belonging to the selected release. Mismatched relationships are rejected.</p>' . self::form('unit', '<p><label>Release <select name="ls_release" required>' . self::options($releases, 'version') . '</select></label> <label>Faction <select name="ls_faction" required>' . self::options($factions) . '</select></label></p><p><label>Unit <input required name="ls_title"></label> <label>Slug <input name="ls_slug"></label> <label>Role <input name="ls_role" placeholder="Example role"></label> <label>Base points <input type="number" name="ls_points" min="0" max="100000" value="0" required></label></p>');
        }
        if ($chamber === 'wargear') {
        echo '<h2>5. Weapons and Wargear</h2><p>Reusable, release-specific profiles. Create equipment before assigning it to a unit.</p>' . self::form('wargear', '<p><label>Release <select name="ls_release" required>' . self::options($releases, 'version') . '</select></label> <label>Name <input required name="ls_title"></label> <label>Slug <input name="ls_slug"></label> <label>Type <select name="ls_equipment[type]"><option value="equipment">Equipment</option><option value="ranged">Ranged weapon</option><option value="melee">Melee weapon</option></select></label></p><p>Weapon profile values and rules can be edited after creation.</p>');
        }
        if ($chamber === 'rules') {
        echo '<h2>6. Codex of War — Rules and Traits</h2><p>Write only descriptions you are authorised to publish. Rules are independent for each release.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_rule_save">' . wp_nonce_field('ls_rule_save', '_wpnonce', true, false) . '<label>Release <select name="ls_release">' . self::options($releases, 'version') . '</select></label> <label>Name <input name="ls_title" required></label> <label>Slug <input name="ls_slug"></label> <label>Type <select name="ls_rule[type]"><option value="special">Special rule</option><option value="trait">Trait</option><option value="weapon">Weapon rule</option><option value="unit">Unit rule</option></select></label><p><label>Description<br><textarea name="ls_rule[description]" rows="3" cols="70"></textarea></label></p><button class="button button-primary">Add rule</button></form>';
        $index_release = isset($_GET['ls_release']) ? absint($_GET['ls_release']) : 0;
        $index_search = isset($_GET['ls_search']) && is_string($_GET['ls_search']) ? substr(sanitize_text_field(wp_unslash($_GET['ls_search'])), 0, 100) : '';
        self::index_filters('rules', $releases, $index_release, $index_search);
        $rule_rows = self::index_matches(self::rows('rules'), $index_release, $index_search);
        $rule_index = self::index_page($rule_rows, isset($_GET['ls_page']) ? absint($_GET['ls_page']) : 1);
        echo '<h3>Rule records (' . $rule_index['total'] . ' matching)</h3><table class="widefat striped"><thead><tr><th>Rule</th><th>Release</th><th>Manage</th></tr></thead><tbody>';
        foreach ($rule_index['rows'] as $rule) {
            $rule_data = json_decode((string)$rule->data, true); $rule_data = is_array($rule_data) ? $rule_data : array();
            echo '<tr><td>' . esc_html($rule->title) . '</td><td>' . (int)$rule->release_id . '</td><td>';
            if (self::release_is_draft((int)$rule->release_id) && !self::release_has_armies((int)$rule->release_id)) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_rule_save"><input type="hidden" name="ls_id" value="' . (int)$rule->id . '">' . wp_nonce_field('ls_rule_save', '_wpnonce', true, false) . '<input name="ls_title" required value="' . esc_attr($rule->title) . '"> <input name="ls_slug" required value="' . esc_attr($rule->slug) . '"> <select name="ls_rule[type]">';
                foreach (array('special'=>'Special rule','trait'=>'Trait','weapon'=>'Weapon rule','unit'=>'Unit rule') as $value=>$label) { echo '<option value="' . esc_attr($value) . '" ' . selected($rule_data['type'] ?? 'special', $value, false) . '>' . esc_html($label) . '</option>'; }
                echo '</select><textarea name="ls_rule[description]" rows="2" cols="55">' . esc_textarea($rule_data['description'] ?? '') . '</textarea> <button class="button">Save</button></form>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(&quot;Delete this unused draft rule?&quot;)"><input type="hidden" name="action" value="ls_rule_delete"><input type="hidden" name="ls_id" value="' . (int)$rule->id . '">' . wp_nonce_field('ls_rule_delete', '_wpnonce', true, false) . '<button class="button button-link-delete">Delete</button></form>';
            } else { echo '<em>Locked</em>'; }
            echo '</td></tr>';
        }
        if (!$rule_rows) { echo '<tr><td colspan="3">No rules match these filters.</td></tr>'; }
        echo '</tbody></table>';
        self::index_pagination('rules', $rule_index, $index_release, $index_search);
        }
        if ($chamber === 'releases') {
        echo '<h2>Release publication</h2><p>Publishing locks catalogue edits. Unused published releases can return to draft; releases used by armies must be cloned for FAQ revisions. Retired releases remain available to existing armies and battle records.</p>';
        foreach ($releases as $release) {
            $edition_title = 'Unknown edition';
            foreach ($editions as $edition) { if ((int)$edition->id === (int)$release->edition_id) { $edition_title = $edition->title; break; } }
            echo '<div class="ls-release-row"><strong>' . esc_html($edition_title . ' — ' . $release->version) . '</strong> <span>' . esc_html($release->status) . '</span>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_armoury_clone"><input type="hidden" name="ls_id" value="' . (int)$release->id . '">' . wp_nonce_field('ls_armoury_clone', '_wpnonce', true, false) . '<label>New draft version <input required maxlength="80" pattern="[A-Za-z0-9][A-Za-z0-9._-]*" name="ls_version" placeholder="2026-draft-2"></label> <button class="button">Clone to draft</button></form>';
            if ($release->status === 'published' || $release->status === 'retired') {
                $has_armies = self::release_has_armies((int)$release->id);
                if ($release->status === 'published' && !$has_armies) {
                    echo self::release_transition_form((int)$release->id, 'draft', 'Return unused release to draft', 'Return this unused release to draft for editing?');
                } elseif ($release->status === 'published') {
                    echo '<p class="description">In use by armies: clone to a new draft to apply FAQ or errata changes.</p>';
                }
                echo self::release_transition_form((int)$release->id, $release->status === 'published' ? 'retired' : 'published', $release->status === 'published' ? 'Retire release' : 'Restore publication', $release->status === 'published' ? 'Retire this release? Existing armies and battles will be retained.' : 'Restore this retired release for new armies?');
            }
            if ($release->status === 'draft') {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(&quot;Publish and permanently lock this release?&quot;);"><input type="hidden" name="action" value="ls_armoury_publish"><input type="hidden" name="ls_id" value="' . (int)$release->id . '">' . wp_nonce_field('ls_armoury_publish', '_wpnonce', true, false) . '<button class="button button-secondary">Publish release</button></form>';
            }
            if ($release->status === 'draft') {
                $preview_id = isset($_GET['ls_delete_preview']) ? absint($_GET['ls_delete_preview']) : 0;
                if ($preview_id === (int)$release->id && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'ls_release_preview_' . (int)$release->id)) {
                    $counts = self::release_deletion_counts((int)$release->id);
                    echo '<div class="ls-delete-release-preview"><p><strong>Permanent deletion preview:</strong> ' . esc_html($edition_title . ' — ' . $release->version) . '</p><p>';
                    foreach ($counts as $name => $count) { echo esc_html(ucfirst($name) . ': ' . $count) . ' &nbsp; '; }
                    echo '</p>';
                    if (self::release_can_be_deleted((int)$release->id)) {
                        echo '<p>This removes all catalogue records listed above. It cannot be undone. Other releases are not affected.</p>';
                        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_armoury_delete_release"><input type="hidden" name="ls_id" value="' . (int)$release->id . '">' . wp_nonce_field('ls_armoury_delete_release', '_wpnonce', true, false) . '<label>Type the exact version to confirm: <strong>' . esc_html($release->version) . '</strong> <input name="ls_confirm_version" required autocomplete="off"></label> <button class="button button-link-delete" onclick="return confirm(&quot;Permanently delete this draft release and its catalogue?&quot;)">Permanently delete draft</button></form>';
                    } else { echo '<p><strong>Deletion blocked:</strong> this release is used by armies or battles.</p>'; }
                    echo '</div>';
                } else {
                    $preview_url = wp_nonce_url(add_query_arg(array('page'=>'legion-strategium', 'ls_chamber'=>'releases', 'ls_delete_preview'=>(int)$release->id), admin_url('admin.php')), 'ls_release_preview_' . (int)$release->id);
                    echo '<a class="button button-link-delete" href="' . esc_url($preview_url) . '">Review draft deletion</a>';
                }
            }
            echo '</div>';
        }
        }
        $edition_names = array(); foreach ($editions as $e) { $edition_names[(int)$e->id] = $e->title; }
        $release_names = array(); foreach ($releases as $r) { $release_names[(int)$r->id] = $r->version; }
        $faction_names = array(); foreach ($factions as $f) { $faction_names[(int)$f->id] = $f->title; }
        $wargear = self::rows('wargear');
        if ($chamber === 'wargear') {
        $index_release = isset($_GET['ls_release']) ? absint($_GET['ls_release']) : 0;
        $index_search = isset($_GET['ls_search']) && is_string($_GET['ls_search']) ? substr(sanitize_text_field(wp_unslash($_GET['ls_search'])), 0, 100) : '';
        self::index_filters('wargear', $releases, $index_release, $index_search);
        $matching = self::index_matches($wargear, $index_release, $index_search);
        $gear_index = self::index_page($matching, isset($_GET['ls_page']) ? absint($_GET['ls_page']) : 1);
        echo '<h2>Wargear records (' . $gear_index['total'] . ' matching)</h2><p>Equipment records are release-scoped and editable while draft.</p><table class="widefat striped"><thead><tr><th>Name</th><th>Release</th><th>Edit draft equipment</th></tr></thead><tbody>';
        foreach ($gear_index['rows'] as $item) { echo '<tr><td>' . esc_html($item->title) . '</td><td>' . esc_html($release_names[(int)$item->release_id] ?? 'Unknown') . '</td><td>' . self::manage_form('wargear', $item, array()) . '</td></tr>'; }
        if (!$matching) { echo '<tr><td colspan="3">No equipment matches these filters.</td></tr>'; }
        echo '</tbody></table>';
        self::index_pagination('wargear', $gear_index, $index_release, $index_search);
        }
        if ($chamber === 'units' || $chamber === 'factions') {
        // Keep the release catalogue scoped before rendering any unit editor.
        $context = self::browse_context($_GET);
        $edition_id = (int)($context['ls_edition'] ?? 0);
        $release_id = (int)($context['ls_release'] ?? 0);
        $faction_id = (int)($context['ls_faction'] ?? 0);
        $search = (string)($context['ls_search'] ?? '');
        $page = max(1, (int)($context['ls_page'] ?? 1));
        $visible_releases = array_values(array_filter($releases, static function ($r) use ($edition_id) { return !$edition_id || (int)$r->edition_id === $edition_id; }));
        if ($release_id && !array_filter($visible_releases, static function ($r) use ($release_id) { return (int)$r->id === $release_id; })) { $release_id = 0; }
        $visible_factions = array_values(array_filter($factions, static function ($f) use ($release_id, $visible_releases) {
            if ($release_id) { return (int)$f->release_id === $release_id; }
            foreach ($visible_releases as $r) { if ((int)$r->id === (int)$f->release_id) { return true; } }
            return false;
        }));
        if ($faction_id && !array_filter($visible_factions, static function ($f) use ($faction_id) { return (int)$f->id === $faction_id; })) { $faction_id = 0; }
        $visible_units = array_values(array_filter($units, static function ($u) use ($visible_factions, $release_id, $faction_id, $search) {
            if ($release_id && (int)$u->release_id !== $release_id) { return false; }
            if ($faction_id && (int)$u->faction_id !== $faction_id) { return false; }
            if ($search !== '' && stripos((string)$u->title, $search) === false && stripos((string)$u->slug, $search) === false) { return false; }
            foreach ($visible_factions as $f) { if ((int)$f->id === (int)$u->faction_id && (int)$f->release_id === (int)$u->release_id) { return true; } }
            return false;
        }));
        if ($chamber === 'units') {
        echo '<h2 id="ls-catalogue">Catalogue browser</h2><form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="ls-catalogue-filters">';
        echo '<input type="hidden" name="page" value="legion-strategium"><input type="hidden" name="ls_chamber" value="units"><label>Edition <select name="ls_edition">' . self::filter_options($editions, 'title', $edition_id, 'All editions') . '</select></label>';
        echo '<label>Release <select name="ls_release">' . self::filter_options($visible_releases, 'version', $release_id, 'All releases') . '</select></label>';
        echo '<label>Faction <select name="ls_faction">' . self::filter_options($visible_factions, 'title', $faction_id, 'All factions') . '</select></label>';
        echo '<label>Search units <input type="search" name="ls_search" value="' . esc_attr($search) . '" placeholder="Unit name or slug"></label><button class="button button-primary">Filter catalogue</button> <a class="button" href="' . esc_url(self::browse_url(array())) . '#ls-catalogue">Clear</a></form>';
        echo '<p class="description">Choose an edition, then filter by release and faction. Apply filters to refresh dependent choices.</p>';
        $selected_edit = isset($_GET['ls_edit_unit']) ? absint($_GET['ls_edit_unit']) : 0;
        $editing = null;
        foreach ($visible_units as $u) { if ((int)$u->id === $selected_edit) { $editing = $u; break; } }
        if ($editing) {
            echo '<section class="ls-datasheet-editor"><h2>Editing: ' . esc_html($editing->title) . '</h2><p><a href="' . esc_url(self::browse_url($context)) . '#ls-catalogue">&larr; Back to catalogue</a></p>';
            $editor = self::manage_form('unit', $editing, $factions);
            $hidden = '';
            foreach ($context as $key=>$value) { $hidden .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr((string)$value) . '">'; }
            // Every form, including delete, retains its browsing context.
            $editor = str_replace('<input type="hidden" name="ls_operation"', $hidden . '<input type="hidden" name="ls_operation"', $editor);
            echo $editor . '</section>';
        }
        echo '<h2>Units (' . count($visible_units) . ' matching)</h2><table class="widefat striped ls-unit-list"><thead><tr><th>Unit</th><th>Faction</th><th>Release</th><th>Actions</th></tr></thead><tbody>';
        $per_page = 25;
        $pages = max(1, (int)ceil(count($visible_units) / $per_page));
        $page = min($page, $pages);
        foreach (array_slice($visible_units, ($page - 1) * $per_page, $per_page) as $u) {
            $link = self::browse_url(array_merge($context, array('ls_edit_unit'=>(int)$u->id)));
            echo '<tr><td>' . esc_html($u->title) . '</td><td>' . esc_html($faction_names[(int)$u->faction_id] ?? 'Unknown faction') . '</td><td>' . esc_html($release_names[(int)$u->release_id] ?? 'Unknown release') . '</td><td><a class="button" href="' . esc_url($link . '#ls-catalogue') . '">View / Edit datasheet</a></td></tr>';
        }
        if (!$visible_units) { echo '<tr><td colspan="4">No units match these filters.</td></tr>'; }
        echo '</tbody></table><nav class="ls-pagination" aria-label="Unit pages">';
        for ($i=1; $i<=$pages; $i++) {
            echo '<a class="button ' . ($i === $page ? 'button-primary' : '') . '" href="' . esc_url(self::browse_url(array_merge($context, array('ls_page'=>$i))) . '#ls-catalogue') . '">' . $i . '</a> ';
        }
        echo '</nav>';
        }
        if ($chamber === 'factions') {
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="ls-catalogue-filters"><input type="hidden" name="page" value="legion-strategium"><input type="hidden" name="ls_chamber" value="factions"><label>Release <select name="ls_release">' . self::filter_options($releases, 'version', $release_id, 'All releases') . '</select></label><button class="button button-primary">Filter factions</button></form>';
        // Keep faction management available but limit it to the selected catalogue.
        echo '<h2>Factions (' . count($visible_factions) . ')</h2><table class="widefat striped"><thead><tr><th>Faction</th><th>Release</th><th>Manage draft</th></tr></thead><tbody>';
        foreach ($visible_factions as $f) {
            echo '<tr><td>' . esc_html($f->title) . '</td><td>' . esc_html($release_names[(int)$f->release_id] ?? 'Unknown release') . '</td><td>' . self::manage_form('faction', $f, $factions) . '</td></tr>';
        }
        echo '</tbody></table>';
        }
        }
        echo '</div>';
        echo '</div>';
    }
    public static function catalogue(): string {
        $editions = self::rows('editions'); $releases = self::rows('releases'); $factions = self::rows('factions'); $units = self::rows('units');
        $release_ids = array(); foreach ($releases as $release) { $release_ids[(int)$release->id] = $release; }
        $faction_ids = array(); foreach ($factions as $faction) { $faction_ids[(int)$faction->id] = $faction; }
        $rules_by_release = array(); foreach (self::rows('rules') as $rule) { $rules_by_release[(int)$rule->release_id][(string)$rule->slug] = $rule; }
        $equipment_by_release = array(); foreach (self::rows('wargear') as $item) { $equipment_by_release[(int)$item->release_id][(string)$item->slug] = $item; }
        $html = '<section class="ls-catalogue"> <h3>The Armoury</h3><p class="ls-muted">Read-only catalogue preview. Army creation and validation arrive in later phases.</p>';
        if (!$units) { return $html . '<p>No units have been added yet. Administrators can populate the catalogue from Strategium in WordPress.</p></section>'; }
        foreach ($editions as $edition) {
            $html .= '<h4>' . esc_html($edition->title) . '</h4>';
            foreach ($releases as $release) {
                if ((int)$release->edition_id !== (int)$edition->id) { continue; }
                $html .= '<h5>Ruleset ' . esc_html($release->version) . '</h5><div class="ls-unit-grid">';
                foreach ($units as $unit) {
                    if ((int)$unit->release_id !== (int)$release->id || !isset($faction_ids[(int)$unit->faction_id]) || (int)$faction_ids[(int)$unit->faction_id]->release_id !== (int)$release->id) { continue; }
                    $data = json_decode((string)$unit->data, true); $data = is_array($data) ? $data : array();
                    $profile = isset($data['profile']) && is_array($data['profile']) ? $data['profile'] : array();
                    $html .= '<article class="ls-unit"><strong>' . esc_html($unit->title) . '</strong><span>' . esc_html($faction_ids[(int)$unit->faction_id]->title) . '</span><span>' . esc_html((string)($data['role'] ?? 'Unassigned')) . '</span><b>' . esc_html((string)($data['base_points'] ?? '—')) . ' pts</b>';
                    if ($profile) { $html .= '<dl class="ls-unit-profile">'; foreach (self::profile_fields() as $key => $label) { if (isset($profile[$key])) { $html .= '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html((string)$profile[$key]) . '</dd></div>'; } } $html .= '</dl>'; }
                    if (!empty($data['equipment_links']) && is_array($data['equipment_links'])) {
                        $html .= '<div class="ls-unit-equipment"><strong>Wargear</strong><ul>';
                        foreach ($data['equipment_links'] as $link) {
                            if (!is_array($link)) { continue; }
                            $item = $equipment_by_release[(int)$release->id][$link['slug'] ?? ''] ?? null;
                            if (!$item) { continue; }
                            $html .= '<li>' . esc_html($item->title) . (!empty($link['model']) ? ' — ' . esc_html($link['model']) : '') . '</li>';
                        }
                        $html .= '</ul></div>';
                    }
                    if (!empty($data['rule_links']) && is_array($data['rule_links'])) {
                        $html .= '<div class="ls-unit-rules"><strong>Special rules and traits</strong><ul>';
                        foreach ($data['rule_links'] as $slug) {
                            $rule = $rules_by_release[(int)$release->id][$slug] ?? null;
                            if (!$rule) { continue; }
                            $rule_data = json_decode((string)$rule->data, true);
                            $description = is_array($rule_data) ? (string)($rule_data['description'] ?? '') : '';
                            $html .= '<li><details><summary>' . esc_html($rule->title) . '</summary><p>' . esc_html($description) . '</p></details></li>';
                        }
                        $html .= '</ul></div>';
                    }
                    $html .= '</article>';
                }
                $html .= '</div>';
            }
        }
        return $html . '</section>';
    }
}
