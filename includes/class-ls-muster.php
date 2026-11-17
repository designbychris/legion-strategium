<?php
if (!defined('ABSPATH')) { exit; }
/** Commander-owned army list foundations; no unit selections until II.5B. */
final class LS_Muster {
    public static function boot(): void {
        add_action('admin_post_ls_muster_create', array(__CLASS__, 'create'));
        add_action('admin_post_ls_muster_rename', array(__CLASS__, 'rename'));
        add_action('admin_post_ls_muster_unit', array(__CLASS__, 'change_unit'));
        add_action('admin_post_ls_muster_allowance', array(__CLASS__, 'change_allowance'));
        add_action('admin_post_ls_muster_detachment', array(__CLASS__, 'change_detachment'));
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
    /** Optional commander-defined points ceiling; zero means no limit selected. */
    public static function points_allowance(array $roster): int {
        $value = $roster['points_allowance'] ?? 0;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) { return 0; }
        $limit = (int)$value;
        return $limit >= 0 && $limit <= 100000 ? $limit : 0;
    }
    /** Count unit selections by their saved datasheet battlefield role, not by live catalogue data.
     * Quantities represent repeated unit selections; models and upgrades do not create new slots.
     */
    public static function battlefield_role_summary(array $roster): array {
        $counts = array();
        $unassigned = 0;
        foreach ($roster['units'] ?? array() as $entry) {
            if (!is_array($entry)) { continue; }
            $data = isset($entry['source_data']) && is_array($entry['source_data']) ? $entry['source_data'] : array();
            $role = isset($data['role']) && is_string($data['role']) ? trim($data['role']) : '';
            $quantity = isset($entry['quantity']) && is_numeric($entry['quantity']) ? (int)$entry['quantity'] : 0;
            if ($quantity < 1 || $quantity > 100) { continue; }
            if ($role === '') { $unassigned += $quantity; continue; }
            $counts[$role] = ($counts[$role] ?? 0) + $quantity;
        }
        ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);
        return array('roles'=>$counts, 'unassigned'=>$unassigned);
    }
    /** Only definitions from this army's release can be selected. */
    public static function detachment_catalogue(int $release_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT id, slug, title, data FROM ' . self::table('detachments') . ' WHERE release_id = %d ORDER BY title, id', $release_id)) ?: array();
    }
    /** Detachment instances are roster-owned; catalogue definitions are copied when added. */
    public static function detachment_review(array $roster): array {
        $detachments = isset($roster['detachments']) && is_array($roster['detachments']) ? $roster['detachments'] : array();
        $ids = array();
        foreach ($detachments as $item) {
            if (!is_array($item) || empty($item['id'])) { continue; }
            $ids[(string)$item['id']] = $item;
        }
        $assigned = array_fill_keys(array_keys($ids), 0);
        $unassigned = 0;
        foreach ($roster['units'] ?? array() as $entry) {
            if (!is_array($entry)) { continue; }
            $quantity = (int)($entry['quantity'] ?? 0);
            if ($quantity < 1 || $quantity > 100) { continue; }
            $id = (string)($entry['detachment_id'] ?? '');
            if ($id !== '' && isset($assigned[$id])) { $assigned[$id] += $quantity; }
            else { $unassigned += $quantity; }
        }
        return array('assigned'=>$assigned, 'unassigned'=>$unassigned, 'fully_validated'=>false);
    }
    /** Advisory slot checks against roster-owned detachment definition snapshots. */
    public static function detachment_slot_review(array $roster): array {
        $instances = array();
        $results = array();
        $issues = array();
        foreach ($roster['detachments'] ?? array() as $detachment) {
            if (!is_array($detachment) || empty($detachment['id'])) { continue; }
            $id = (string)$detachment['id'];
            if (isset($instances[$id])) { continue; }
            $instances[$id] = $detachment;
            $results[$id] = array('title'=>(string)($detachment['title'] ?? 'Detachment'), 'roles'=>array(), 'slots'=>array(), 'issues'=>array(), 'assigned'=>0);
        }
        $unassigned = 0;
        foreach ($roster['units'] ?? array() as $entry) {
            if (!is_array($entry)) { continue; }
            $quantity = $entry['quantity'] ?? 0;
            if (!is_numeric($quantity) || (int)$quantity < 1 || (int)$quantity > 100) { continue; }
            $quantity = (int)$quantity;
            $id = (string)($entry['detachment_id'] ?? '');
            if ($id === '' || !isset($results[$id])) { $unassigned += $quantity; continue; }
            $data = isset($entry['source_data']) && is_array($entry['source_data']) ? $entry['source_data'] : array();
            $role = isset($data['role']) && is_string($data['role']) ? trim($data['role']) : '';
            $results[$id]['assigned'] += $quantity;
            $results[$id]['roles'][$role] = ($results[$id]['roles'][$role] ?? 0) + $quantity;
        }
        foreach ($instances as $id => $detachment) {
            $data = isset($detachment['source_data']) && is_array($detachment['source_data']) ? $detachment['source_data'] : array();
            $slots = isset($data['slots']) && is_array($data['slots']) ? $data['slots'] : array();
            $limits = array();
            foreach ($slots as $slot) {
                if (!is_array($slot)) { continue; }
                $role = isset($slot['role']) && is_string($slot['role']) ? trim($slot['role']) : '';
                if ($role === '') { continue; }
                $min = $slot['min'] ?? null;
                $max = $slot['max'] ?? null;
                if (!is_numeric($min) || !is_numeric($max) || (int)$min < 0 || (int)$max < (int)$min || (int)$max > 100) {
                    $results[$id]['issues'][] = 'Invalid saved slot limits for ' . $role . '.';
                    continue;
                }
                if (isset($limits[$role])) {
                    $results[$id]['issues'][] = 'Duplicate saved slot definition for ' . $role . '.';
                    continue;
                }
                $limits[$role] = array('min'=>(int)$min, 'max'=>(int)$max);
            }
            $results[$id]['slots'] = $limits;
            if (!$limits) { $results[$id]['issues'][] = 'No battlefield-role slots defined for this detachment.'; }
            foreach ($limits as $role => $limit) {
                $count = $results[$id]['roles'][$role] ?? 0;
                if ($count < $limit['min']) { $results[$id]['issues'][] = $role . ': requires at least ' . $limit['min'] . ' selection(s); ' . $count . ' assigned.'; }
                if ($count > $limit['max']) { $results[$id]['issues'][] = $role . ': allows at most ' . $limit['max'] . ' selection(s); ' . $count . ' assigned.'; }
            }
            foreach ($results[$id]['roles'] as $role => $count) {
                if ($role === '') { $results[$id]['issues'][] = $count . ' selection(s) have no battlefield role.'; }
                elseif (!isset($limits[$role])) { $results[$id]['issues'][] = $role . ': ' . $count . ' selection(s) are not permitted by this detachment.'; }
            }
            foreach ($results[$id]['issues'] as $issue) { $issues[] = $results[$id]['title'] . ': ' . $issue; }
        }
        if ($unassigned > 0) { $issues[] = $unassigned . ' unit selection(s) are not assigned to a detachment.'; }
        return array('detachments'=>$results, 'unassigned'=>$unassigned, 'issues'=>$issues, 'slots_checked'=>count($results) > 0, 'fully_validated'=>false);
    }
    public static function change_detachment(): void {
        if (!is_user_logged_in()) { wp_die(esc_html__('Sign in to edit an army.', 'legion-strategium')); }
        check_admin_referer('ls_muster_detachment');
        $army_id = isset($_POST['ls_army_id']) ? absint($_POST['ls_army_id']) : 0;
        $operation = isset($_POST['ls_operation']) ? sanitize_key(wp_unslash((string)$_POST['ls_operation'])) : '';
        if (!$army_id || !in_array($operation, array('add','remove','assign'), true)) { self::redirect('invalid_detachment'); }
        global $wpdb;
        $army = $wpdb->get_row($wpdb->prepare('SELECT id, release_id, roster FROM ' . self::table('armies') . ' WHERE id = %d AND user_id = %d', $army_id, get_current_user_id()));
        if (!$army) { self::redirect('invalid_detachment'); }
        $roster = self::roster_from_json((string)$army->roster);
        $detachments = isset($roster['detachments']) && is_array($roster['detachments']) ? array_values($roster['detachments']) : array();
        $instance = isset($_POST['ls_detachment_id']) && is_scalar($_POST['ls_detachment_id']) ? sanitize_key(wp_unslash((string)$_POST['ls_detachment_id'])) : '';
        if ($operation === 'add') {
            if (count($detachments) >= 20) { self::redirect('invalid_detachment'); }
            $source_id = isset($_POST['ls_definition_id']) ? absint($_POST['ls_definition_id']) : 0;
            $source = null;
            foreach (self::detachment_catalogue((int)$army->release_id) as $definition) {
                if ((int)$definition->id === $source_id) { $source = $definition; break; }
            }
            if (!$source) { self::redirect('invalid_detachment'); }
            $data = json_decode((string)$source->data, true);
            $detachments[] = array('id'=>wp_generate_uuid4(), 'source_id'=>(int)$source->id, 'slug'=>(string)$source->slug, 'title'=>(string)$source->title, 'source_data'=>is_array($data) ? $data : array());
        } elseif ($operation === 'remove') {
            $found = false;
            foreach ($detachments as $i=>$item) {
                if (($item['id'] ?? '') !== $instance || $instance === '') { continue; }
                array_splice($detachments, $i, 1);
                foreach ($roster['units'] as &$entry) {
                    if (is_array($entry) && ($entry['detachment_id'] ?? '') === $instance) { unset($entry['detachment_id']); }
                }
                unset($entry);
                $found = true; break;
            }
            if (!$found) { self::redirect('invalid_detachment'); }
        } else {
            $entry_id = isset($_POST['ls_entry_id']) && is_scalar($_POST['ls_entry_id']) ? sanitize_key(wp_unslash((string)$_POST['ls_entry_id'])) : '';
            if ($instance !== '' && !in_array($instance, array_column($detachments, 'id'), true)) { self::redirect('invalid_detachment'); }
            $found = false;
            foreach ($roster['units'] as &$entry) {
                if (!is_array($entry) || ($entry['entry_id'] ?? '') !== $entry_id || $entry_id === '') { continue; }
                if ($instance === '') { unset($entry['detachment_id']); }
                else { $entry['detachment_id'] = $instance; }
                $found = true; break;
            }
            unset($entry);
            if (!$found) { self::redirect('invalid_detachment'); }
        }
        $roster['detachments'] = $detachments;
        $updated = $wpdb->update(self::table('armies'), array('roster'=>wp_json_encode($roster), 'updated_at'=>current_time('mysql', true)), array('id'=>$army_id, 'user_id'=>get_current_user_id()), array('%s','%s'), array('%d','%d'));
        self::redirect($updated === false ? 'error_unit' : 'detachment_saved');
    }
    /** Composition policy is intentionally absent until release-specific limits are authored. */
    public static function composition_review(array $roster): array {
        $summary = self::battlefield_role_summary($roster);
        $issues = array();
        if ($summary['unassigned'] > 0) {
            $issues[] = $summary['unassigned'] . ' unit selection(s) have no battlefield role in their saved datasheet.';
        }
        return array('summary'=>$summary, 'issues'=>$issues, 'limits_configured'=>false, 'fully_validated'=>false);
    }
    /** Advisory checks: never prevent a commander from saving a work-in-progress. */
    public static function muster_review(array $roster): array {
        $total = self::roster_points($roster);
        $limit = self::points_allowance($roster);
        $issues = array();
        if ($limit > 0 && $total > $limit) { $issues[] = 'Over the points allowance by ' . ($total - $limit) . ' pts.'; }
        if (empty($roster['units'])) { $issues[] = 'No units have been added yet.'; }
        foreach ($roster['units'] ?? array() as $index => $entry) {
            if (!is_array($entry) || empty($entry['entry_id']) || empty($entry['title']) || !isset($entry['base_points']) || !is_numeric($entry['base_points']) || (float)$entry['base_points'] < 0 || !isset($entry['quantity']) || !is_numeric($entry['quantity']) || (int)$entry['quantity'] < 1 || (int)$entry['quantity'] > 100) {
                $issues[] = 'Roster entry ' . ((int)$index + 1) . ' has incomplete or invalid data.';
            }
        }
        return array('total'=>$total, 'allowance'=>$limit, 'remaining'=>$limit > 0 ? $limit - $total : null, 'issues'=>$issues);
    }
    public static function change_allowance(): void {
        if (!is_user_logged_in()) { wp_die(esc_html__('Sign in to edit an army.', 'legion-strategium')); }
        check_admin_referer('ls_muster_allowance');
        $army_id = isset($_POST['ls_army_id']) ? absint($_POST['ls_army_id']) : 0;
        $raw = isset($_POST['ls_points_allowance']) && is_scalar($_POST['ls_points_allowance']) ? trim((string)wp_unslash($_POST['ls_points_allowance'])) : '';
        if (!$army_id || !ctype_digit($raw) || (float)$raw > 100000) { self::redirect('invalid_allowance'); }
        global $wpdb;
        $army = $wpdb->get_row($wpdb->prepare('SELECT id, roster FROM ' . self::table('armies') . ' WHERE id = %d AND user_id = %d', $army_id, get_current_user_id()));
        if (!$army) { self::redirect('invalid_allowance'); }
        $roster = self::roster_from_json((string)$army->roster);
        $roster['points_allowance'] = (int)$raw;
        $updated = $wpdb->update(self::table('armies'), array('roster'=>wp_json_encode($roster), 'updated_at'=>current_time('mysql', true)), array('id'=>$army_id, 'user_id'=>get_current_user_id()), array('%s','%s'), array('%d','%d'));
        self::redirect($updated === false ? 'error_unit' : 'allowance_saved');
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
        $review = self::muster_review($roster);
        $allowance = $review['allowance'];
        $out .= '<div class="ls-muster-review"><h5>Praetor’s points review</h5>';
        $out .= '<form class="ls-muster-allowance" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_muster_allowance"><input type="hidden" name="ls_army_id" value="' . (int)$army->id . '"><input type="hidden" name="ls_return" value="' . esc_attr($return) . '">' . wp_nonce_field('ls_muster_allowance', '_wpnonce', true, false) . '<label>Points allowance <input type="number" name="ls_points_allowance" min="0" max="100000" step="1" value="' . (int)$allowance . '" required></label><button class="ls-button ls-secondary" type="submit">Save allowance</button></form>';
        if ($allowance > 0) {
            $remaining = $review['remaining'];
            $out .= '<p class="ls-muster-balance">' . esc_html(number_format_i18n($total)) . ' / ' . esc_html(number_format_i18n($allowance)) . ' pts · ' . esc_html(number_format_i18n(abs($remaining))) . ($remaining < 0 ? ' pts over' : ' pts remaining') . '</p>';
        } else { $out .= '<p class="ls-muted">No points allowance selected. Enter a limit above to check your muster.</p>'; }
        if ($review['issues']) {
            $out .= '<ul class="ls-muster-issues" aria-label="Muster review notices">';
            foreach ($review['issues'] as $issue) { $out .= '<li>' . esc_html($issue) . '</li>'; }
            $out .= '</ul>';
        } else { $out .= '<p class="ls-muster-points-ok">Points and basic roster checks passed.</p>'; }
        $composition = self::composition_review($roster);
        $out .= '<section class="ls-muster-roles" aria-label="Battlefield role summary"><h5>Battlefield roles</h5>';
        if ($composition['summary']['roles']) {
            $out .= '<ul class="ls-muster-role-list">';
            foreach ($composition['summary']['roles'] as $role => $count) {
                $out .= '<li><span>' . esc_html($role) . '</span><strong>' . (int)$count . '</strong></li>';
            }
            $out .= '</ul>';
        } else { $out .= '<p class="ls-muted">No assigned battlefield roles yet.</p>'; }
        if ($composition['issues']) {
            $out .= '<ul class="ls-muster-issues" aria-label="Battlefield role notices">';
            foreach ($composition['issues'] as $issue) { $out .= '<li>' . esc_html($issue) . '</li>'; }
            $out .= '</ul>';
        }
        $out .= '<p class="ls-muted">Role counts use saved unit datasheets. Detachment slot checks appear below when definitions are available. Other release-specific army restrictions are not yet validated.</p></section></div>';

        $detachments = isset($roster['detachments']) && is_array($roster['detachments']) ? $roster['detachments'] : array();
        $detachment_review = self::detachment_review($roster);
        $slot_review = self::detachment_slot_review($roster);
        $out .= '<section class="ls-muster-detachments" aria-label="Army detachments"><h5>Army detachments</h5>';
        if (!$detachments) { $out .= '<p class="ls-muted">No detachments selected yet.</p>'; }
        foreach ($detachments as $item) {
            if (!is_array($item) || empty($item['id'])) { continue; }
            $out .= '<div class="ls-detachment-row"><strong>' . esc_html((string)($item['title'] ?? 'Detachment')) . '</strong> <span>' . (int)($detachment_review['assigned'][(string)$item['id']] ?? 0) . ' unit selections</span>';
            $out .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_muster_detachment"><input type="hidden" name="ls_operation" value="remove"><input type="hidden" name="ls_army_id" value="' . (int)$army->id . '"><input type="hidden" name="ls_detachment_id" value="' . esc_attr((string)$item['id']) . '"><input type="hidden" name="ls_return" value="' . esc_attr($return) . '">' . wp_nonce_field('ls_muster_detachment', '_wpnonce', true, false) . '<button class="ls-button ls-secondary" type="submit">Remove detachment</button></form></div>';
        }
        $definitions = self::detachment_catalogue((int)$army->release_id);
        if ($definitions && count($detachments) < 20) {
            $out .= '<form class="ls-detachment-add" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_muster_detachment"><input type="hidden" name="ls_operation" value="add"><input type="hidden" name="ls_army_id" value="' . (int)$army->id . '"><input type="hidden" name="ls_return" value="' . esc_attr($return) . '">' . wp_nonce_field('ls_muster_detachment', '_wpnonce', true, false) . '<label>Detachment type <select name="ls_definition_id">';
            foreach ($definitions as $definition) { $out .= '<option value="' . (int)$definition->id . '">' . esc_html((string)$definition->title) . '</option>'; }
            $out .= '</select></label><button class="ls-button ls-secondary" type="submit">Add detachment</button></form>';
        } elseif (!$definitions) { $out .= '<p class="ls-muted">No detachment definitions are available in this rules release yet. Definitions must be authored before detachments can be added.</p>'; }
        foreach ($slot_review['detachments'] as $checked) {
            $out .= '<div class="ls-detachment-check"><strong>' . esc_html($checked['title']) . ' — slot review</strong>';
            if ($checked['slots']) {
                $out .= '<ul class="ls-muster-role-list">';
                foreach ($checked['slots'] as $role => $limit) {
                    $out .= '<li><span>' . esc_html($role) . ' (' . (int)$limit['min'] . '–' . (int)$limit['max'] . ')</span><strong>' . (int)($checked['roles'][$role] ?? 0) . '</strong></li>';
                }
                $out .= '</ul>';
            }
            if ($checked['issues']) {
                $out .= '<ul class="ls-muster-issues">';
                foreach ($checked['issues'] as $issue) { $out .= '<li>' . esc_html($issue) . '</li>'; }
                $out .= '</ul>';
            } else { $out .= '<p class="ls-muted">Saved detachment slot requirements passed.</p>'; }
            $out .= '</div>';
        }
        if ($slot_review['unassigned']) { $out .= '<p class="ls-muted">' . (int)$slot_review['unassigned'] . ' unit selection(s) are not assigned to a detachment.</p>'; }
        $out .= '<p class="ls-muted">Detachment slot checks use saved release snapshots and are advisory. Other Third Edition requirements are not yet validated; this is not full army legality.</p></section>';
        if (empty($roster['units'])) { $out .= '<p class="ls-muted">No warriors assigned yet.</p>'; }
        foreach ($roster['units'] as $entry) {
            if (!is_array($entry)) { continue; }
            $id = (string)($entry['entry_id'] ?? '');
            $qty = max(1, (int)($entry['quantity'] ?? 1));
            $points = max(0, (int)($entry['base_points'] ?? 0));
            $configured_points = self::entry_points($entry);
            $out .= '<div class="ls-roster-entry"><div class="ls-roster-unit-heading"><strong>' . esc_html((string)($entry['title'] ?? 'Unit')) . '</strong><small>' . esc_html((string)$points) . ' pts each · ' . esc_html((string)$configured_points) . ' pts total</small></div>';
            $out .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_muster_unit"><input type="hidden" name="ls_operation" value="quantity"><input type="hidden" name="ls_army_id" value="' . (int)$army->id . '"><input type="hidden" name="ls_entry_id" value="' . esc_attr($id) . '"><input type="hidden" name="ls_return" value="' . esc_attr($return) . '">' . wp_nonce_field('ls_muster_unit', '_wpnonce', true, false) . '<label>Quantity <input type="number" name="ls_quantity" min="1" max="100" value="' . $qty . '" required></label><button class="ls-button ls-secondary" type="submit">Update</button></form>';
            if ($detachments) {
                $out .= '<form class="ls-detachment-assign" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_muster_detachment"><input type="hidden" name="ls_operation" value="assign"><input type="hidden" name="ls_army_id" value="' . (int)$army->id . '"><input type="hidden" name="ls_entry_id" value="' . esc_attr($id) . '"><input type="hidden" name="ls_return" value="' . esc_attr($return) . '">' . wp_nonce_field('ls_muster_detachment', '_wpnonce', true, false) . '<label>Detachment <select name="ls_detachment_id"><option value="">Unassigned</option>';
                foreach ($detachments as $item) {
                    if (!is_array($item) || empty($item['id'])) { continue; }
                    $selected = ($entry['detachment_id'] ?? '') === $item['id'] ? ' selected' : '';
                    $out .= '<option value="' . esc_attr((string)$item['id']) . '"' . $selected . '>' . esc_html((string)($item['title'] ?? 'Detachment')) . '</option>';
                }
                $out .= '</select></label><button class="ls-button ls-secondary" type="submit">Assign</button></form>';
            }
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
        $messages = array('created'=>'Army muster created.', 'renamed'=>'Army renamed.', 'invalid'=>'Please choose a valid title, published release and faction.', 'error'=>'Could not create the army. Please try again.', 'not_changed'=>'No changes were saved.', 'unit_saved'=>'Army roster updated.', 'invalid_unit'=>'Invalid unit, quantity or army. No changes were saved.', 'error_unit'=>'Could not save the roster. Please try again.', 'allowance_saved'=>'Points allowance saved.', 'invalid_allowance'=>'Invalid points allowance or army. No changes were saved.', 'detachment_saved'=>'Army detachments updated.', 'invalid_detachment'=>'Invalid detachment selection. No changes were saved.');
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
