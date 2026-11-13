<?php
if (!defined('ABSPATH')) { exit; }
final class LS_Plugin {
    public static function boot(): void {
        LS_Accounts::boot();
        LS_Armoury::boot();
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_shortcode('legion_strategium', array(__CLASS__, 'shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));
    }
    public static function admin_menu(): void {
        add_menu_page(__('Legion Strategium', 'legion-strategium'), __('Strategium', 'legion-strategium'), 'manage_options', 'legion-strategium', array(__CLASS__, 'admin_page'), 'dashicons-shield', 58);
    }
    public static function admin_page(): void {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Access denied.', 'legion-strategium')); }
        LS_Armoury::admin();
    }
    public static function assets(): void {
        global $post;
        if (!$post || !has_shortcode((string)$post->post_content, 'legion_strategium')) { return; }
        wp_enqueue_style('ls-shell', LS_URL . 'assets/strategium.css', array(), LS_VERSION);
    }
    public static function shortcode(): string {
        $return = get_permalink() ?: home_url('/');
        $head = '<section class="ls-shell"><p class="ls-kicker">LEGION STRATEGIUM</p><h2>The Legion Strategium</h2><p>Army Builder &amp; Battle Companion</p>' . LS_Accounts::notice();
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            return $head . '<div class="ls-command"><p class="ls-kicker">COMMAND DECK</p><h3>Welcome, ' . esc_html(LS_Accounts::commander_name((int)$user->ID)) . '</h3><p>Your command dashboard is ready. The Armoury is now open for browsing. Army creation will arrive in a later phase.</p><div class="ls-stat-grid"><div><strong>0</strong><span>Armies</span></div><div><strong>0</strong><span>Battles</span></div><div><strong>3rd</strong><span>Initial edition</span></div></div>' . LS_Armoury::catalogue() . LS_Accounts::profile_form($return) . LS_Accounts::account_form($return) . LS_Accounts::form('logout', $return) . '</div></section>';
        }
        $register = (bool)get_option('users_can_register');
        $mode = isset($_GET['ls_view']) && sanitize_key(wp_unslash($_GET['ls_view'])) === 'register' && $register ? 'register' : 'login';
        $switch = $mode === 'login' && $register ? '<a href="' . esc_url(add_query_arg('ls_view', 'register', $return)) . '">Create a commander account</a>' : '<a href="' . esc_url(remove_query_arg('ls_view', $return)) . '">Already enlisted? Sign in</a>';
        $body = '<div class="ls-auth"><h3>' . ($mode === 'register' ? 'Enlist a Commander' : 'Commander Sign In') . '</h3>' . LS_Accounts::form($mode, $return) . '<p class="ls-auth-switch">' . $switch . '</p>';
        if ($mode === 'login') { $body .= '<p><a href="' . esc_url(wp_lostpassword_url($return)) . '">Forgot your password?</a></p>'; }
        if ($mode === 'login') { $body .= LS_Accounts::resend_form($return); }
        if (!$register) { $body .= '<p class="ls-muted">Public registration is not enabled yet.</p>'; }
        return $head . $body . '</div></section>';
    }
    public static function routes(): void {
        register_rest_route('legion-strategium/v1', '/status', array('methods' => 'GET', 'callback' => array(__CLASS__, 'status'), 'permission_callback' => '__return_true'));
        register_rest_route('legion-strategium/v1', '/me', array('methods' => 'GET', 'callback' => array(__CLASS__, 'me'), 'permission_callback' => 'is_user_logged_in'));
    }
    public static function status(): WP_REST_Response {
        return new WP_REST_Response(array('app' => 'legion-strategium', 'version' => LS_VERSION, 'schema' => LS_Schema::VERSION), 200);
    }
    public static function me(): WP_REST_Response {
        $user = wp_get_current_user();
        return new WP_REST_Response(array('id' => (int)$user->ID, 'display_name' => $user->display_name), 200);
    }
}
