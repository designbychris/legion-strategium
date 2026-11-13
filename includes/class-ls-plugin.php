<?php
if (!defined('ABSPATH')) { exit; }
final class LS_Plugin {
    public static function boot(): void {
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
        echo '<div class="wrap"><h1>' . esc_html__('The Legion Strategium', 'legion-strategium') . '</h1><p>' . esc_html__('Phase I.1 foundation installed. Edition and ruleset management will be introduced in subsequent phases.', 'legion-strategium') . '</p><p><strong>' . esc_html__('Database schema:', 'legion-strategium') . '</strong> ' . esc_html((string)get_option('ls_schema_version', 'not installed')) . '</p></div>';
    }
    public static function assets(): void {
        global $post;
        if (!$post || !has_shortcode((string)$post->post_content, 'legion_strategium')) { return; }
        wp_enqueue_style('ls-shell', LS_URL . 'assets/strategium.css', array(), LS_VERSION);
    }
    public static function shortcode(): string {
        $title = esc_html__('The Legion Strategium', 'legion-strategium');
        $intro = esc_html__('Army Builder & Battle Companion', 'legion-strategium');
        if (!is_user_logged_in()) {
            return '<section class="ls-shell"><p class="ls-kicker">LEGION STRATEGIUM</p><h2>' . $title . '</h2><p>' . $intro . '</p><p>' . esc_html__('Sign in to access your armies.', 'legion-strategium') . '</p><a class="ls-button" href="' . esc_url(wp_login_url(get_permalink())) . '">' . esc_html__('Sign in', 'legion-strategium') . '</a></section>';
        }
        return '<section class="ls-shell"><p class="ls-kicker">LEGION STRATEGIUM</p><h2>' . $title . '</h2><p>' . $intro . '</p><p>' . esc_html__('Your command dashboard is being prepared. No official game data is included in this foundation release.', 'legion-strategium') . '</p></section>';
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
