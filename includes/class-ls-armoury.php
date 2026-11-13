<?php
if (!defined('ABSPATH')) { exit; }
/** Phase II.1: edition-scoped catalogue administration and public read-only browsing. */
final class LS_Armoury {
    public static function boot(): void {
        add_action('admin_post_ls_armoury_save', array(__CLASS__, 'save'));
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
            $result = $wpdb->insert(LS_Schema::table('factions'), array('release_id'=>$release,'slug'=>$slug,'title'=>$title,'data'=>'{}'), array('%d','%s','%s','%s'));
        } elseif ($kind === 'unit' && $release && $faction && $title && $slug && $points !== false && $points >= 0 && $points <= 100000 && self::faction_in_release($faction, $release)) {
            $data = wp_json_encode(array('role'=>$role,'base_points'=>$points));
            $result = $wpdb->insert(LS_Schema::table('units'), array('release_id'=>$release,'faction_id'=>$faction,'slug'=>$slug,'title'=>$title,'data'=>$data), array('%d','%d','%s','%s','%s'));
        }
        wp_safe_redirect(add_query_arg(array('page'=>'legion-strategium','ls_armoury_notice'=>$result === 1 ? 'saved' : 'invalid'), admin_url('admin.php')));
        exit;
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
        echo '<div class="wrap"><h1>The Armoury Archives</h1><p>Phase II.1 — draft catalogue. Use sample or authorised data only. Records are tied to their ruleset release.</p>';
        if (isset($_GET['ls_armoury_notice'])) { echo '<div class="notice notice-info"><p>' . (sanitize_key(wp_unslash($_GET['ls_armoury_notice'])) === 'saved' ? 'Catalogue record added.' : 'Could not save: check fields, relationships, or duplicate slugs.') . '</p></div>'; }
        echo '<h2>1. Editions</h2>' . self::form('edition', '<p><label>Title <input required name="ls_title" placeholder="Horus Heresy Third Edition"></label> <label>Slug <input name="ls_slug" placeholder="hh-3"></label></p>');
        echo '<h2>2. Ruleset Releases</h2>' . self::form('release', '<p><label>Edition <select name="ls_edition" required>' . self::options($editions) . '</select></label> <label>Version <input required name="ls_version" placeholder="2026-draft-1"></label></p>');
        echo '<h2>3. Factions</h2>' . self::form('faction', '<p><label>Release <select name="ls_release" required>' . self::options($releases, 'version') . '</select></label> <label>Name <input required name="ls_title"></label> <label>Slug <input name="ls_slug"></label></p>');
        echo '<h2>4. Units</h2><p>Choose a faction belonging to the selected release. Mismatched relationships are rejected.</p>' . self::form('unit', '<p><label>Release <select name="ls_release" required>' . self::options($releases, 'version') . '</select></label> <label>Faction <select name="ls_faction" required>' . self::options($factions) . '</select></label></p><p><label>Unit <input required name="ls_title"></label> <label>Slug <input name="ls_slug"></label> <label>Role <input name="ls_role" placeholder="Example role"></label> <label>Base points <input type="number" name="ls_points" min="0" max="100000" value="0" required></label></p>');
        foreach (array('editions'=>$editions,'releases'=>$releases,'factions'=>$factions,'units'=>$units) as $name=>$records) {
            echo '<h2>' . esc_html(ucfirst($name)) . ' (' . count($records) . ')</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Name / Version</th><th>Parent</th></tr></thead><tbody>';
            foreach ($records as $row) { echo '<tr><td>' . (int)$row->id . '</td><td>' . esc_html((string)($row->title ?? $row->version ?? '')) . '</td><td>' . esc_html((string)($row->release_id ?? $row->edition_id ?? '—')) . '</td></tr>'; }
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
                    $html .= '<article class="ls-unit"><strong>' . esc_html($unit->title) . '</strong><span>' . esc_html($faction_ids[(int)$unit->faction_id]->title) . '</span><span>' . esc_html((string)($data['role'] ?? 'Unassigned')) . '</span><b>' . esc_html((string)($data['base_points'] ?? '—')) . ' pts</b></article>';
                }
                $html .= '</div>';
            }
        }
        return $html . '</section>';
    }
}
