<?php
if (!defined('ABSPATH')) { exit; }
/** Edition-scoped catalogue administration and public read-only browsing. */
final class LS_Armoury {
    public static function boot(): void {
        add_action('admin_post_ls_armoury_save', array(__CLASS__, 'save'));
        add_action('admin_post_ls_armoury_manage', array(__CLASS__, 'manage'));
        add_action('admin_post_ls_armoury_publish', array(__CLASS__, 'publish'));
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
        wp_safe_redirect(add_query_arg(array('page'=>'legion-strategium','ls_armoury_notice'=>$notice), admin_url('admin.php')));
        exit;
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
        wp_safe_redirect(add_query_arg(array('page'=>'legion-strategium','ls_armoury_notice'=>$notice), admin_url('admin.php')));
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
    public static function admin(): void {
        if (!current_user_can('manage_options')) { wp_die('Access denied.'); }
        $editions = self::rows('editions'); $releases = self::rows('releases'); $factions = self::rows('factions'); $units = self::rows('units');
        echo '<div class="wrap ls-armoury-admin"><h1>The Armoury Archives</h1><p>Draft catalogue. Use sample or authorised data only. Published releases are locked.</p>';
        if (isset($_GET['ls_armoury_notice'])) { echo '<div class="notice notice-info"><p>' . (array('saved'=>'Catalogue record added.','updated'=>'Draft record updated.','deleted'=>'Draft record deleted.','published'=>'Ruleset published and locked.') [sanitize_key(wp_unslash($_GET['ls_armoury_notice']))] ?? 'Could not change record: check relationships, duplicate slugs, or whether the release is locked or in use.') . '</p></div>'; }
        echo '<h2>1. Editions</h2>' . self::form('edition', '<p><label>Title <input required name="ls_title" placeholder="Horus Heresy Third Edition"></label> <label>Slug <input name="ls_slug" placeholder="hh-3"></label></p>');
        echo '<h2>2. Ruleset Releases</h2>' . self::form('release', '<p><label>Edition <select name="ls_edition" required>' . self::options($editions) . '</select></label> <label>Version <input required name="ls_version" placeholder="2026-draft-1"></label></p>');
        echo '<h2>3. Factions</h2>' . self::form('faction', '<p><label>Release <select name="ls_release" required>' . self::options($releases, 'version') . '</select></label> <label>Name <input required name="ls_title"></label> <label>Slug <input name="ls_slug"></label></p>');
        echo '<h2>4. Units</h2><p>Choose a faction belonging to the selected release. Mismatched relationships are rejected.</p>' . self::form('unit', '<p><label>Release <select name="ls_release" required>' . self::options($releases, 'version') . '</select></label> <label>Faction <select name="ls_faction" required>' . self::options($factions) . '</select></label></p><p><label>Unit <input required name="ls_title"></label> <label>Slug <input name="ls_slug"></label> <label>Role <input name="ls_role" placeholder="Example role"></label> <label>Base points <input type="number" name="ls_points" min="0" max="100000" value="0" required></label></p>');
        echo '<h2>Release publication</h2><p>Publishing permanently locks this release. Check all factions, units and points first. A release must contain at least one faction and unit.</p>';
        foreach ($releases as $release) {
            $edition_title = 'Unknown edition';
            foreach ($editions as $edition) { if ((int)$edition->id === (int)$release->edition_id) { $edition_title = $edition->title; break; } }
            echo '<div class="ls-release-row"><strong>' . esc_html($edition_title . ' — ' . $release->version) . '</strong> <span>' . esc_html($release->status) . '</span>';
            if ($release->status === 'draft') {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(&quot;Publish and permanently lock this release?&quot;);"><input type="hidden" name="action" value="ls_armoury_publish"><input type="hidden" name="ls_id" value="' . (int)$release->id . '">' . wp_nonce_field('ls_armoury_publish', '_wpnonce', true, false) . '<button class="button button-secondary">Publish release</button></form>';
            }
            echo '</div>';
        }
        $edition_names = array(); foreach ($editions as $e) { $edition_names[(int)$e->id] = $e->title; }
        $release_names = array(); foreach ($releases as $r) { $release_names[(int)$r->id] = $r->version; }
        $faction_names = array(); foreach ($factions as $f) { $faction_names[(int)$f->id] = $f->title; }
        foreach (array('editions'=>$editions,'releases'=>$releases,'factions'=>$factions,'units'=>$units) as $name=>$records) {
            echo '<h2>' . esc_html(ucfirst($name)) . ' (' . count($records) . ')</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Name / Version</th><th>Parent</th><th>Manage draft records</th></tr></thead><tbody>';
            foreach ($records as $row) { echo '<tr><td>' . (int)$row->id . '</td><td>' . esc_html((string)($row->title ?? $row->version ?? '')) . '</td><td>' . esc_html($name === 'units' ? ($faction_names[(int)$row->faction_id] ?? 'Unknown faction') : ($name === 'factions' ? ($release_names[(int)$row->release_id] ?? 'Unknown release') : ($name === 'releases' ? ($edition_names[(int)$row->edition_id] ?? 'Unknown edition') : '—'))) . '</td><td>' . (in_array($name, array('units','factions'), true) ? self::manage_form($name === 'units' ? 'unit' : 'faction', $row, $factions) : '—') . '</td></tr>'; }
            echo '</tbody></table>';
        }
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
