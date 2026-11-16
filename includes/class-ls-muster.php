<?php
if (!defined('ABSPATH')) { exit; }
/** Commander-owned army list foundations; no unit selections until II.5B. */
final class LS_Muster {
    public static function boot(): void {
        add_action('admin_post_ls_muster_create', array(__CLASS__, 'create'));
        add_action('admin_post_ls_muster_rename', array(__CLASS__, 'rename'));
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
    public static function render(string $return): string {
        if (!is_user_logged_in()) { return ''; }
        global $wpdb;
        $user_id = get_current_user_id();
        $armies = $wpdb->get_results($wpdb->prepare('SELECT a.id, a.title, a.release_id, a.roster, a.updated_at, r.version, e.title AS edition_title FROM ' . self::table('armies') . ' a LEFT JOIN ' . self::table('releases') . ' r ON r.id = a.release_id LEFT JOIN ' . self::table('editions') . ' e ON e.id = r.edition_id WHERE a.user_id = %d ORDER BY a.updated_at DESC, a.id DESC LIMIT 100', $user_id)) ?: array();
        $releases = self::available_releases();
        $notice = isset($_GET['ls_muster_notice']) ? sanitize_key(wp_unslash((string)$_GET['ls_muster_notice'])) : '';
        $messages = array('created'=>'Army muster created.', 'renamed'=>'Army renamed.', 'invalid'=>'Please choose a valid title, published release and faction.', 'error'=>'Could not create the army. Please try again.', 'not_changed'=>'No changes were saved.');
        $out = '<section class="ls-muster" id="ls-muster"><p class="ls-kicker">THE MUSTER ROLLS</p><h3>My Armies</h3><p>Create a private army and choose its edition, rules release and faction. Unit selection arrives in the next phase.</p>';
        if (isset($messages[$notice])) { $out .= '<p class="ls-notice" role="status">' . esc_html($messages[$notice]) . '</p>'; }
        $out .= '<div class="ls-muster-list">';
        if (!$armies) { $out .= '<p class="ls-muted">No armies mustered yet. Begin your first force below.</p>'; }
        foreach ($armies as $army) {
            $roster = json_decode((string)$army->roster, true);
            $faction_id = is_array($roster) ? (int)($roster['faction_id'] ?? 0) : 0;
            $faction_name = 'Unassigned faction';
            foreach (self::factions((int)$army->release_id) as $faction) { if ((int)$faction->id === $faction_id) { $faction_name = (string)$faction->title; break; } }
            $out .= '<article class="ls-muster-army"><strong>' . esc_html($army->title) . '</strong><span>' . esc_html((string)$army->edition_title . ' · ' . (string)$army->version . ' · ' . $faction_name) . '</span><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_muster_rename"><input type="hidden" name="ls_army_id" value="' . (int)$army->id . '"><input type="hidden" name="ls_return" value="' . esc_attr($return) . '">' . wp_nonce_field('ls_muster_rename', '_wpnonce', true, false) . '<label>Army name <input name="ls_army_title" maxlength="190" required value="' . esc_attr($army->title) . '"></label><button class="ls-button ls-secondary" type="submit">Rename</button></form></article>';
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
