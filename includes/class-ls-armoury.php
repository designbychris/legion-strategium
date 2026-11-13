<?php
if (!defined('ABSPATH')) { exit; }
/** Edition-scoped catalogue administration and public read-only browsing. */
final class LS_Armoury {
    public static function boot(): void {
        add_action('admin_post_ls_armoury_save', array(__CLASS__, 'save'));
        add_action('admin_post_ls_armoury_manage', array(__CLASS__, 'manage'));
        add_action('admin_post_ls_armoury_publish', array(__CLASS__, 'publish'));
        add_action('admin_post_ls_armoury_clone', array(__CLASS__, 'clone_release'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_assets'));
    }
    public static function admin_assets(string $hook): void {
        if ($hook === 'toplevel_page_legion-strategium') {
            wp_enqueue_style('ls-armoury-admin', LS_URL . 'assets/armoury-admin.css', array(), LS_VERSION);
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

    /** Publishing is one-way. Further rules changes require a separate draft release. */
    public static function publish(): void {
        if (!current_user_can('manage_options')) { wp_die('Access denied.'); }
        check_admin_referer('ls_armoury_publish');
        $id = isset($_POST['ls_id']) ? absint($_POST['ls_id']) : 0;
        global $wpdb;
        $notice = 'invalid';
        if ($id && self::release_is_draft($id)) {
            $faction_count = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . LS_Schema::table('factions') . ' WHERE release_id = %d', $id));
            $unit_count = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . LS_Schema::table('units') . ' WHERE release_id = %d', $id));
            $invalid_units = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . LS_Schema::table('units') . ' u LEFT JOIN ' . LS_Schema::table('factions') . ' f ON u.faction_id = f.id AND u.release_id = f.release_id WHERE u.release_id = %d AND f.id IS NULL', $id));
            if ($faction_count > 0 && $unit_count > 0 && $invalid_units === 0) {
                $updated = $wpdb->update(LS_Schema::table('releases'), array('status'=>'published','published_at'=>current_time('mysql')), array('id'=>$id,'status'=>'draft'), array('%s','%s'), array('%d','%s'));
                $notice = $updated === 1 ? 'published' : 'invalid';
            }
        }
        $context = self::browse_context($_POST);
        if ($kind === 'unit' && $operation === 'edit' && $notice === 'updated') { $context['ls_edit_unit'] = $id; }
        wp_safe_redirect(add_query_arg(array_merge(array('page'=>'legion-strategium','ls_armoury_notice'=>$notice), $context), admin_url('admin.php')));
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
        if ($kind === 'unit' && $operation === 'edit' && $notice === 'updated') { $context['ls_edit_unit'] = $id; }
        wp_safe_redirect(add_query_arg(array_merge(array('page'=>'legion-strategium','ls_armoury_notice'=>$notice), $context), admin_url('admin.php')));
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
        foreach (array('detachments','wargear') as $table) {
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
        } elseif ($kind === 'unit' && $release && $faction && $title && $slug && $points !== false && $points >= 0 && $points <= 100000 && self::faction_in_release($faction, $release)) {
            $data = wp_json_encode(array('role'=>$role,'base_points'=>$points));
            $result = self::release_is_draft($release) ? $wpdb->insert(LS_Schema::table('units'), array('release_id'=>$release,'faction_id'=>$faction,'slug'=>$slug,'title'=>$title,'data'=>$data), array('%d','%d','%s','%s','%s')) : false;
        }
        wp_safe_redirect(add_query_arg(array('page'=>'legion-strategium','ls_armoury_notice'=>$result === 1 ? 'saved' : 'invalid'), admin_url('admin.php')));
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
        if ($id && in_array($kind, array('unit','faction'), true) && in_array($operation, array('edit','delete'), true)) {
            $table = $kind === 'unit' ? 'units' : 'factions';
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
                                $profile_input = isset($_POST['ls_profile']) && is_array($_POST['ls_profile']) ? wp_unslash($_POST['ls_profile']) : array();
                                $data['profile'] = self::sanitise_profile($profile_input);
                                $values['faction_id'] = $faction;
                                $values['data'] = wp_json_encode($data);
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
        wp_safe_redirect(add_query_arg(array_merge(array('page'=>'legion-strategium','ls_armoury_notice'=>$notice), $context), admin_url('admin.php')));
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
        if ($kind === 'unit') {
            $profile = isset($data['profile']) && is_array($data['profile']) ? $data['profile'] : array();
            $form .= '<div class="ls-profile-fields"><strong>Characteristics</strong><div class="ls-profile-grid">';
            foreach (self::profile_fields() as $key => $label) {
                $form .= '<label>' . esc_html($label) . '<input maxlength="32" name="ls_profile[' . esc_attr($key) . ']" value="' . esc_attr((string)($profile[$key] ?? '')) . '" placeholder="—"></label>';
            }
            $form .= '</div></div>';
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
        return add_query_arg(array_merge(array('page'=>'legion-strategium'), $context), admin_url('admin.php'));
    }
    public static function admin(): void {
        if (!current_user_can('manage_options')) { wp_die('Access denied.'); }
        $editions = self::rows('editions'); $releases = self::rows('releases'); $factions = self::rows('factions'); $units = self::rows('units');
        echo '<div class="wrap ls-armoury-admin"><h1>The Armoury Archives</h1><p>Draft catalogue. Use sample or authorised data only. Published releases are locked.</p>';
        if (isset($_GET['ls_armoury_notice'])) { echo '<div class="notice notice-info"><p>' . (array('saved'=>'Catalogue record added.','updated'=>'Draft record updated.','deleted'=>'Draft record deleted.','published'=>'Ruleset published and locked.','cloned'=>'New independent draft release created.','clone_failed'=>'Could not clone release. Check version uniqueness and catalogue integrity.') [sanitize_key(wp_unslash($_GET['ls_armoury_notice']))] ?? 'Could not change record: check relationships, duplicate slugs, or whether the release is locked or in use.') . '</p></div>'; }
        echo '<h2>1. Editions</h2>' . self::form('edition', '<p><label>Title <input required name="ls_title" placeholder="Horus Heresy Third Edition"></label> <label>Slug <input name="ls_slug" placeholder="hh-3"></label></p>');
        echo '<h2>2. Ruleset Releases</h2>' . self::form('release', '<p><label>Edition <select name="ls_edition" required>' . self::options($editions) . '</select></label> <label>Version <input required name="ls_version" placeholder="2026-draft-1"></label></p>');
        echo '<h2>3. Factions</h2>' . self::form('faction', '<p><label>Release <select name="ls_release" required>' . self::options($releases, 'version') . '</select></label> <label>Name <input required name="ls_title"></label> <label>Slug <input name="ls_slug"></label></p>');
        echo '<h2>4. Units</h2><p>Choose a faction belonging to the selected release. Mismatched relationships are rejected.</p>' . self::form('unit', '<p><label>Release <select name="ls_release" required>' . self::options($releases, 'version') . '</select></label> <label>Faction <select name="ls_faction" required>' . self::options($factions) . '</select></label></p><p><label>Unit <input required name="ls_title"></label> <label>Slug <input name="ls_slug"></label> <label>Role <input name="ls_role" placeholder="Example role"></label> <label>Base points <input type="number" name="ls_points" min="0" max="100000" value="0" required></label></p>');
        echo '<h2>Release publication</h2><p>Publishing permanently locks this release. Check all factions, units and points first. A release must contain at least one faction and unit.</p>';
        foreach ($releases as $release) {
            $edition_title = 'Unknown edition';
            foreach ($editions as $edition) { if ((int)$edition->id === (int)$release->edition_id) { $edition_title = $edition->title; break; } }
            echo '<div class="ls-release-row"><strong>' . esc_html($edition_title . ' — ' . $release->version) . '</strong> <span>' . esc_html($release->status) . '</span>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_armoury_clone"><input type="hidden" name="ls_id" value="' . (int)$release->id . '">' . wp_nonce_field('ls_armoury_clone', '_wpnonce', true, false) . '<label>New draft version <input required maxlength="80" pattern="[A-Za-z0-9][A-Za-z0-9._-]*" name="ls_version" placeholder="2026-draft-2"></label> <button class="button">Clone to draft</button></form>';
            if ($release->status === 'draft') {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(&quot;Publish and permanently lock this release?&quot;);"><input type="hidden" name="action" value="ls_armoury_publish"><input type="hidden" name="ls_id" value="' . (int)$release->id . '">' . wp_nonce_field('ls_armoury_publish', '_wpnonce', true, false) . '<button class="button button-secondary">Publish release</button></form>';
            }
            echo '</div>';
        }
        $edition_names = array(); foreach ($editions as $e) { $edition_names[(int)$e->id] = $e->title; }
        $release_names = array(); foreach ($releases as $r) { $release_names[(int)$r->id] = $r->version; }
        $faction_names = array(); foreach ($factions as $f) { $faction_names[(int)$f->id] = $f->title; }
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
        echo '<h2 id="ls-catalogue">Catalogue browser</h2><form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="ls-catalogue-filters">';
        echo '<input type="hidden" name="page" value="legion-strategium"><label>Edition <select name="ls_edition">' . self::filter_options($editions, 'title', $edition_id, 'All editions') . '</select></label>';
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
        // Keep faction management available but limit it to the selected catalogue.
        echo '<h2>Factions (' . count($visible_factions) . ')</h2><table class="widefat striped"><thead><tr><th>Faction</th><th>Release</th><th>Manage draft</th></tr></thead><tbody>';
        foreach ($visible_factions as $f) {
            echo '<tr><td>' . esc_html($f->title) . '</td><td>' . esc_html($release_names[(int)$f->release_id] ?? 'Unknown release') . '</td><td>' . self::manage_form('faction', $f, $factions) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }
    public static function catalogue(): string {
        $editions = self::rows('editions'); $releases = self::rows('releases'); $factions = self::rows('factions'); $units = self::rows('units');
        $release_ids = array(); foreach ($releases as $release) { $release_ids[(int)$release->id] = $release; }
        $faction_ids = array(); foreach ($factions as $faction) { $faction_ids[(int)$faction->id] = $faction; }
        $html = '<section class="ls-catalogue"><h3>The Armoury</h3><p class="ls-muted">Read-only catalogue preview. Army creation and validation arrive in later phases.</p>';
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
                    $html .= '</article>';
                }
                $html .= '</div>';
            }
        }
        return $html . '</section>';
    }
}
