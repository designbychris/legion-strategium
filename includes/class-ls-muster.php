<?php
if (!defined('ABSPATH')) { exit; }
/** Commander-owned army list foundations; no unit selections until II.5B. */
final class LS_Muster {
    public static function boot(): void {
        add_action('admin_post_ls_muster_create', array(__CLASS__, 'create'));
        add_action('admin_post_ls_muster_rename', array(__CLASS__, 'rename'));
        add_action('admin_post_ls_muster_unit', array(__CLASS__, 'change_unit'));
    }
    public static function table(string $name): string { return LS_Schema::table($name); }
    public static function return_url(): string {
        $raw = isset($_POST['ls_return']) && is_scalar($_POST['ls_return']) ? esc_url_raw(wp_unslash((string)$_POST['ls_return'])) : home_url('/');
        return wp_validate_redirect($raw, home_url('/'));
    }
    public static function redirect(string $notice): void {
        wp_safe_redirect(add_query_arg('ls_muster_notice', $notice, self::return_url()));
        exit;
    }
    public static function available_releases(): array {
        global $wpdb;
        return $wpdb->get_results('SELECT r.id, r.version, r.edition_id, e.title AS edition_title FROM ' . self::table('releases') . ' r INNER JOIN ' . self::table('editions') . " e ON e.id = r.edition_id WHERE r.status = 'published' AND e.status = 'published' ORDER BY e.title, r.version DESC") ?: array();
    }
    public static function factions(int $release_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT id, title FROM ' . self::table('factions') . ' WHERE release_id = %d ORDER BY title', $release_id)) ?: array();
    }
    public static function valid_faction(int $release_id, int $faction_id): bool {
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('factions') . ' WHERE id = %d AND release_id = %d', $faction_id, $release_id));
    }
    public static function valid_release(int $release_id): bool {
        foreach (self::available_releases() as $release) { if ((int)$release->id === $release_id) { return true; } }
        return false;
    }
    public static function create(): void {
        if (!is_user_logged_in()) { wp_die(esc_html__('Sign in to create an army.', 'legion-strategium')); }
        check_admin_referer('ls_muster_create');
        $title = isset($_POST['ls_army_title']) && is_scalar($_POST['ls_army_title']) ? sanitize_text_field(wp_unslash((string)$_POST['ls_army_title'])) : '';
        $release_id = isset($_POST['ls_release_id']) ? absint($_POST['ls_release_id']) : 0;
        $faction_id = isset($_POST['ls_faction_id']) ? absint($_POST['ls_faction_id']) : 0;
        if ($title === '' || strlen($title) > 190 || !self::valid_release($release_id) || !self::valid_faction($release_id, $faction_id)) { self::redirect('invalid'); }
        global $wpdb;
        $now = current_time('mysql', true);
        $ok = $wpdb->insert(self::table('armies'), array('user_id'=>get_current_user_id(), 'release_id'=>$release_id, 'title'=>$title, 'status'=>'private', 'roster'=>wp_json_encode(array('faction_id'=>$faction_id, 'units'=>array(), 'schema'=>1)), 'created_at'=>$now, 'updated_at'=>$now), array('%d','%d','%s','%s','%s','%s','%s'));
        self::redirect($ok ? 'created' : 'error');
    }
    public static function rename(): void {
        if (!is_user_logged_in()) { wp_die(esc_html__('Sign in to edit an army.', 'legion-strategium')); }
        check_admin_referer('ls_muster_rename');
        $id = isset($_POST['ls_army_id']) ? absint($_POST['ls_army_id']) : 0;
        $title = isset($_POST['ls_army_title']) && is_scalar($_POST['ls_army_title']) ? sanitize_text_field(wp_unslash((string)$_POST['ls_army_title'])) : '';
        if (!$id || $title === '' || strlen($title) > 190) { self::redirect('invalid'); }
        global $wpdb;
        $updated = $wpdb->update(self::table('armies'), array('title'=>$title, 'updated_at'=>current_time('mysql', true)), array('id'=>$id, 'user_id'=>get_current_user_id()), array('%s','%s'), array('%d','%d'));
        self::redirect($updated === false || $updated === 0 ? 'not_changed' : 'renamed');
    }
    /** Catalogue records are read-only sources; army entries are independent snapshots. */
    public static function catalogue_units(int $release_id, int $faction_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT id, title, slug, data FROM ' . self::table('units') . ' WHERE release_id = %d AND faction_id = %d ORDER BY title, id', $release_id, $faction_id)) ?: array();
    }
    public static function roster_from_json(string $json): array {
        $roster = json_decode($json, true);
        if (!is_array($roster)) { return array('schema'=>1, 'faction_id'=>0, 'units'=>array()); }
        if (!isset($roster['units']) || !is_array($roster['units'])) { $roster['units'] = array(); }
        return $roster;
    }
    /** Options are taken only from the entry's frozen source datasheet. */
    public static function entry_options(array $entry): array {
        $data = $entry['source_data'] ?? array();
        return is_array($data) && isset($data['unit_options']) && is_array($data['unit_options'])
            ? array_values(array_filter(array_slice($data['unit_options'], 0, 30), 'is_array')) : array();
    }
    public static function entry_points(array $entry): int {
        $base = max(0, (int)($entry['base_points'] ?? 0));
        $quantity = max(1, min(100, (int)($entry['quantity'] ?? 1)));
        $adjustment = 0;
        $choices = isset($entry['choices']) && is_array($entry['choices']) ? $entry['choices'] : array();
        foreach (self::entry_options($entry) as $index => $option) {
            $count = isset($choices[$index]) ? (int)$choices[$index] : 0;
            $adjustment += $count * (int)($option['points'] ?? 0);
        }
        return max(0, ($base + $adjustment) * $quantity);
    }
    /** Reject malformed counts and unknown options, never trust client-supplied points. */
    public static function validated_choices(array $entry, $submitted) {
        if (!is_array($submitted)) { return false; }
        $options = self::entry_options($entry);
        if (count($submitted) > count($options)) { return false; }
        foreach ($submitted as $key => $value) {
            if (!ctype_digit((string)$key) || !array_key_exists((int)$key, $options) || !is_scalar($value)) { return false; }
        }
        $choices = array();
        foreach ($options as $index => $option) {
            $raw = $submitted[$index] ?? 0;
            $count = filter_var($raw, FILTER_VALIDATE_INT);
            $minimum = max(0, (int)($option['min'] ?? 0));
            $maximum = min(100, (int)($option['max'] ?? 0));
            if ($count === false || $maximum < $minimum || $count < $minimum || $count > $maximum) { return false; }
            $choices[$index] = $count;
        }
        return $choices;
    }
    public static function roster_points(array $roster): int {
        $total = 0;
        foreach ($roster['units'] ?? array() as $entry) {
            if (!is_array($entry)) { continue; }
            $total += self::entry_points($entry);
        }
        return $total;
    }
    public static function change_unit(): void {
        if (!is_user_logged_in()) { wp_die(esc_html__('Sign in to edit an army.', 'legion-strategium')); }
        check_admin_referer('ls_muster_unit');
        $army_id = isset($_POST['ls_army_id']) ? absint($_POST['ls_army_id']) : 0;
        $operation = isset($_POST['ls_operation']) ? sanitize_key(wp_unslash((string)$_POST['ls_operation'])) : '';
        $unit_id = isset($_POST['ls_unit_id']) ? absint($_POST['ls_unit_id']) : 0;
        $entry_id = isset($_POST['ls_entry_id']) ? sanitize_key(wp_unslash((string)$_POST['ls_entry_id'])) : '';
        $quantity = isset($_POST['ls_quantity']) ? filter_var(wp_unslash($_POST['ls_quantity']), FILTER_VALIDATE_INT) : false;
        if (!$army_id || !in_array($operation, array('add', 'quantity', 'remove', 'configure'), true)) { self::redirect('invalid_unit'); }
        global $wpdb;
        $army = $wpdb->get_row($wpdb->prepare('SELECT id, release_id, roster FROM ' . self::table('armies') . ' WHERE id = %d AND user_id = %d', $army_id, get_current_user_id()));
        if (!$army) { self::redirect('invalid_unit'); }
        $roster = self::roster_from_json((string)$army->roster);
        $units = array_values($roster['units']);
        if ($operation === 'add') {
            if ($quantity === false || $quantity < 1 || $quantity > 100 || count($units) >= 200) { self::redirect('invalid_unit'); }
            $source = null;
            foreach (self::catalogue_units((int)$army->release_id, (int)($roster['faction_id'] ?? 0)) as $candidate) {
                if ((int)$candidate->id === $unit_id) { $source = $candidate; break; }
            }
            if (!$source) { self::redirect('invalid_unit'); }
            $data = json_decode((string)$source->data, true);
            $points = is_array($data) ? ($data['base_points'] ?? null) : null;
            if (!is_numeric($points) || (int)$points < 0 || (int)$points > 100000) { self::redirect('invalid_unit'); }
            $units[] = array('entry_id'=>wp_generate_uuid4(), 'catalogue_unit_id'=>(int)$source->id, 'slug'=>(string)$source->slug, 'title'=>(string)$source->title, 'base_points'=>(int)$points, 'quantity'=>$quantity, 'choices'=>array(), 'source_data'=>is_array($data) ? $data : array());
        } else {
            $found = false;
            foreach ($units as $index=>$entry) {
                if (!is_array($entry) || ($entry['entry_id'] ?? '') !== $entry_id || $entry_id === '') { continue; }
                $found = true;
                if ($operation === 'remove') { array_splice($units, $index, 1); }
                elseif ($operation === 'configure') {
                    $raw = isset($_POST['ls_choices']) ? wp_unslash($_POST['ls_choices']) : array();
                    $choices = self::validated_choices($entry, $raw);
                    if ($choices === false) { self::redirect('invalid_unit'); }
                    $units[$index]['choices'] = $choices;
                } elseif ($quantity !== false && $quantity >= 1 && $quantity <= 100) { $units[$index]['quantity'] = $quantity; }
                else { self::redirect('invalid_unit'); }
                break;
            }
            if (!$found) { self::redirect('invalid_unit'); }
        }
        $roster['units'] = $units;
        $updated = $wpdb->update(self::table('armies'), array('roster'=>wp_json_encode($roster), 'updated_at'=>current_time('mysql', true)), array('id'=>$army_id, 'user_id'=>get_current_user_id()), array('%s','%s'), array('%d','%d'));
        self::redirect($updated === false ? 'error_unit' : 'unit_saved');
    }
    public static function render_roster(object $army, array $roster, string $return): string {
        $total = self::roster_points($roster);
        $out = '<div class="ls-roster"><h4>Army roster <span>' . esc_html(number_format_i18n($total)) . ' pts</span></h4>';
        if (empty($roster['units'])) { $out .= '<p class="ls-muted">No warriors assigned yet.</p>'; }
        foreach ($roster['units'] as $entry) {
            if (!is_array($entry)) { continue; }
            $id = (string)($entry['entry_id'] ?? '');
            $qty = max(1, (int)($entry['quantity'] ?? 1));
            $points = max(0, (int)($entry['base_points'] ?? 0));
            $configured_points = self::entry_points($entry);
            $out .= '<div class="ls-roster-entry"><div><strong>' . esc_html((string)($entry['title'] ?? 'Unit')) . '</strong><small>' . esc_html((string)$points) . ' pts each · ' . esc_html((string)$configured_points) . ' pts total</small></div>';
            $out .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_muster_unit"><input type="hidden" name="ls_operation" value="quantity"><input type="hidden" name="ls_army_id" value="' . (int)$army->id . '"><input type="hidden" name="ls_entry_id" value="' . esc_attr($id) . '"><input type="hidden" name="ls_return" value="' . esc_attr($return) . '">' . wp_nonce_field('ls_muster_unit', '_wpnonce', true, false) . '<label>Quantity <input type="number" name="ls_quantity" min="1" max="100" value="' . $qty . '" required></label><button class="ls-button ls-secondary" type="submit">Update</button></form>';
            $options = self::entry_options($entry);
            if ($options) {
                $out .= '<form class="ls-roster-config" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_muster_unit"><input type="hidden" name="ls_operation" value="configure"><input type="hidden" name="ls_army_id" value="' . (int)$army->id . '"><input type="hidden" name="ls_entry_id" value="' . esc_attr($id) . '"><input type="hidden" name="ls_return" value="' . esc_attr($return) . '">' . wp_nonce_field('ls_muster_unit', '_wpnonce', true, false);
                $out .= '<fieldset><legend>Quartermaster options (per unit)</legend>';
                foreach ($options as $index => $option) {
                    $min = max(0, (int)($option['min'] ?? 0));
                    $max = min(100, (int)($option['max'] ?? 0));
                    $current = isset($entry['choices'][$index]) ? (int)$entry['choices'][$index] : 0;
                    $current = max($min, min($max, $current));
                    $label = (string)($option['name'] ?? 'Upgrade');
                    $out .= '<label>' . esc_html($label . ' (' . (int)($option['points'] ?? 0) . ' pts)') . ' <input type="number" name="ls_choices[' . (int)$index . ']" min="' . $min . '" max="' . $max . '" value="' . $current . '" required></label>';
                }
                $out .= '</fieldset><button class="ls-button ls-secondary" type="submit">Save equipment &amp; upgrades</button></form>';
            }
            $out .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_muster_unit"><input type="hidden" name="ls_operation" value="remove"><input type="hidden" name="ls_army_id" value="' . (int)$army->id . '"><input type="hidden" name="ls_entry_id" value="' . esc_attr($id) . '"><input type="hidden" name="ls_return" value="' . esc_attr($return) . '">' . wp_nonce_field('ls_muster_unit', '_wpnonce', true, false) . '<button class="ls-button ls-secondary" type="submit">Remove</button></form></div>';
        }
        $sources = self::catalogue_units((int)$army->release_id, (int)($roster['faction_id'] ?? 0));
        if ($sources) {
            $out .= '<form class="ls-roster-add" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_muster_unit"><input type="hidden" name="ls_operation" value="add"><input type="hidden" name="ls_army_id" value="' . (int)$army->id . '"><input type="hidden" name="ls_return" value="' . esc_attr($return) . '">' . wp_nonce_field('ls_muster_unit', '_wpnonce', true, false) . '<label>Add unit <select name="ls_unit_id" required>';
            foreach ($sources as $source) { $data = json_decode((string)$source->data, true); $points = is_array($data) ? (int)($data['base_points'] ?? 0) : 0; $out .= '<option value="' . (int)$source->id . '">' . esc_html($source->title . ' — ' . $points . ' pts') . '</option>'; }
            $out .= '</select></label><label>Quantity <input type="number" name="ls_quantity" min="1" max="100" value="1" required></label><button class="ls-button" type="submit">Add to Army</button></form>';
        } else { $out .= '<p class="ls-muted">No units are available for this army’s faction and release.</p>'; }
        return $out . '<p class="ls-muted">Unit points include saved Quartermaster selections. Army-wide validation arrives in a later phase.</p></div>';
    }
    public static function render(string $return): string {
        if (!is_user_logged_in()) { return ''; }
        global $wpdb;
        $user_id = get_current_user_id();
        $armies = $wpdb->get_results($wpdb->prepare('SELECT a.id, a.title, a.release_id, a.roster, a.updated_at, r.version, e.title AS edition_title FROM ' . self::table('armies') . ' a LEFT JOIN ' . self::table('releases') . ' r ON r.id = a.release_id LEFT JOIN ' . self::table('editions') . ' e ON e.id = r.edition_id WHERE a.user_id = %d ORDER BY a.updated_at DESC, a.id DESC LIMIT 100', $user_id)) ?: array();
        $releases = self::available_releases();
        $notice = isset($_GET['ls_muster_notice']) ? sanitize_key(wp_unslash((string)$_GET['ls_muster_notice'])) : '';
        $messages = array('created'=>'Army muster created.', 'renamed'=>'Army renamed.', 'invalid'=>'Please choose a valid title, published release and faction.', 'error'=>'Could not create the army. Please try again.', 'not_changed'=>'No changes were saved.', 'unit_saved'=>'Army roster updated.', 'invalid_unit'=>'Invalid unit, quantity or army. No changes were saved.', 'error_unit'=>'Could not save the roster. Please try again.');
        $out = '<section class="ls-muster" id="ls-muster"><p class="ls-kicker">THE MUSTER ROLLS</p><h3>My Armies</h3><p>Create a private army and choose its edition, rules release and faction. Select units from your faction catalogue and assemble your force below.</p>';
        if (isset($messages[$notice])) { $out .= '<p class="ls-notice" role="status">' . esc_html($messages[$notice]) . '</p>'; }
        $out .= '<div class="ls-muster-list">';
        if (!$armies) { $out .= '<p class="ls-muted">No armies mustered yet. Begin your first force below.</p>'; }
        foreach ($armies as $army) {
            $roster = self::roster_from_json((string)$army->roster);
            $faction_id = is_array($roster) ? (int)($roster['faction_id'] ?? 0) : 0;
            $faction_name = 'Unassigned faction';
            foreach (self::factions((int)$army->release_id) as $faction) { if ((int)$faction->id === $faction_id) { $faction_name = (string)$faction->title; break; } }
            $out .= '<article class="ls-muster-army"><strong>' . esc_html($army->title) . '</strong><span>' . esc_html((string)$army->edition_title . ' · ' . (string)$army->version . ' · ' . $faction_name) . '</span><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_muster_rename"><input type="hidden" name="ls_army_id" value="' . (int)$army->id . '"><input type="hidden" name="ls_return" value="' . esc_attr($return) . '">' . wp_nonce_field('ls_muster_rename', '_wpnonce', true, false) . '<label>Army name <input name="ls_army_title" maxlength="190" required value="' . esc_attr($army->title) . '"></label><button class="ls-button ls-secondary" type="submit">Rename</button></form>' . self::render_roster($army, $roster, $return) . '</article>';
        }
        $out .= '</div><h4>Raise a New Army</h4>';
        if (!$releases) { return $out . '<p class="ls-muted">No published editions and releases are available yet. Ask an administrator to publish a rules release.</p></section>'; }
        $out .= '<form class="ls-muster-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_muster_create"><input type="hidden" name="ls_return" value="' . esc_attr($return) . '">' . wp_nonce_field('ls_muster_create', '_wpnonce', true, false) . '<label>Army name <input type="text" name="ls_army_title" maxlength="190" required placeholder="e.g. Firedrake Spearhead"></label><label>Edition and release <select name="ls_release_id" id="ls-muster-release" required>';
        foreach ($releases as $release) { $out .= '<option value="' . (int)$release->id . '">' . esc_html($release->edition_title . ' — ' . $release->version) . '</option>'; }
        $out .= '</select></label><label>Faction <select name="ls_faction_id" id="ls-muster-faction" required>';
        $faction_options = array();
        foreach ($releases as $release) { foreach (self::factions((int)$release->id) as $faction) { $faction_options[] = array('release'=>(int)$release->id, 'id'=>(int)$faction->id, 'title'=>(string)$faction->title); } }
        foreach ($faction_options as $faction) { $out .= '<option data-release="' . $faction['release'] . '" value="' . $faction['id'] . '">' . esc_html($faction['title']) . '</option>'; }
        $out .= '</select></label><button class="ls-button" type="submit">Create Army</button></form><script>(function(){var r=document.getElementById("ls-muster-release"),f=document.getElementById("ls-muster-faction");if(!r||!f)return;function sync(){var first=null;Array.prototype.forEach.call(f.options,function(o){o.hidden=o.disabled=o.getAttribute("data-release")!==r.value;if(!o.disabled&&!first)first=o;});if(first)f.value=first.value;f.disabled=!first;}r.addEventListener("change",sync);sync();}());</script></section>';
        return $out;
    }
}
