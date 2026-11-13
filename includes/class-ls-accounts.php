<?php
if (!defined('ABSPATH')) { exit; }
final class LS_Accounts {
    public static function boot(): void {
        foreach (array('login','register','logout') as $action) {
            add_action('admin_post_nopriv_ls_' . $action, array(__CLASS__, $action));
            add_action('admin_post_ls_' . $action, array(__CLASS__, $action));
        }
    }
    private static function return_url(): string {
        $url = isset($_POST['ls_return']) ? esc_url_raw(wp_unslash($_POST['ls_return'])) : home_url('/');
        return wp_validate_redirect($url, home_url('/'));
    }
    private static function redirect(string $code, string $url): void {
        wp_safe_redirect(add_query_arg('ls_notice', $code, $url));
        exit;
    }
    private static function verify(string $action): bool {
        return isset($_POST['ls_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ls_nonce'])), 'ls_' . $action);
    }
    public static function login(): void {
        $url = self::return_url();
        if (!self::verify('login')) { self::redirect('expired', $url); }
        if (is_user_logged_in()) { self::redirect('signed_in', $url); }
        $username = isset($_POST['ls_identity']) ? sanitize_text_field(wp_unslash($_POST['ls_identity'])) : '';
        $password = isset($_POST['ls_password']) && is_string($_POST['ls_password']) ? wp_unslash($_POST['ls_password']) : '';
        $user = wp_signon(array('user_login' => $username, 'user_password' => $password, 'remember' => !empty($_POST['ls_remember'])), is_ssl());
        if (is_wp_error($user)) { self::redirect('invalid_login', $url); }
        self::redirect('signed_in', $url);
    }
    public static function register(): void {
        $url = self::return_url();
        if (!self::verify('register')) { self::redirect('expired', $url); }
        if (is_user_logged_in()) { self::redirect('signed_in', $url); }
        if (!get_option('users_can_register')) { self::redirect('closed', $url); }
        $name = isset($_POST['ls_username']) ? sanitize_user(wp_unslash($_POST['ls_username']), true) : '';
        $email = isset($_POST['ls_email']) ? sanitize_email(wp_unslash($_POST['ls_email'])) : '';
        $password = isset($_POST['ls_password']) && is_string($_POST['ls_password']) ? wp_unslash($_POST['ls_password']) : '';
        if (!$name || !validate_username($name) || !is_email($email) || strlen($password) < 12 || strlen($password) > 4096) { self::redirect('invalid_registration', $url); }
        if (username_exists($name) || email_exists($email)) { self::redirect('unavailable', $url); }
        // Fixed subscriber role: never accept roles/capabilities from a public request.
        $id = wp_insert_user(array('user_login' => $name, 'user_email' => $email, 'user_pass' => $password, 'role' => 'subscriber'));
        if (is_wp_error($id)) { self::redirect('invalid_registration', $url); }
        wp_new_user_notification($id, null, 'user');
        self::redirect('registered', $url);
    }
    public static function logout(): void {
        $url = self::return_url();
        if (!self::verify('logout') || !is_user_logged_in()) { self::redirect('expired', $url); }
        wp_logout();
        self::redirect('signed_out', $url);
    }
    public static function notice(): string {
        $messages = array('expired' => 'Your session expired. Please try again.', 'invalid_login' => 'Sign-in failed. Please check your credentials.', 'signed_in' => 'Welcome to the Strategium.', 'registered' => 'Account created. You can now sign in.', 'closed' => 'New registrations are currently closed.', 'invalid_registration' => 'Please provide a valid commander name, email, and password of at least 12 characters.', 'unavailable' => 'That commander name or email is unavailable.', 'signed_out' => 'You have signed out.');
        $code = isset($_GET['ls_notice']) ? sanitize_key(wp_unslash($_GET['ls_notice'])) : '';
        return isset($messages[$code]) ? '<p class="ls-notice" role="status">' . esc_html($messages[$code]) . '</p>' : '';
    }
    public static function form(string $action, string $return): string {
        $url = esc_url(admin_url('admin-post.php'));
        $hidden = '<input type="hidden" name="action" value="ls_' . esc_attr($action) . '"><input type="hidden" name="ls_return" value="' . esc_attr($return) . '">' . wp_nonce_field('ls_' . $action, 'ls_nonce', true, false);
        if ($action === 'logout') { return '<form method="post" action="' . $url . '">' . $hidden . '<button class="ls-button ls-secondary" type="submit">Sign out</button></form>'; }
        $fields = $action === 'register'
            ? '<label>Commander name<input required autocomplete="username" name="ls_username" minlength="3" maxlength="60"></label><label>Email address<input required type="email" autocomplete="email" name="ls_email"></label>'
            : '<label>Commander name or email<input required autocomplete="username" name="ls_identity"></label>';
        $fields .= '<label>Password<input required type="password" name="ls_password" autocomplete="' . ($action === 'register' ? 'new-password' : 'current-password') . '" ' . ($action === 'register' ? 'minlength="12"' : '') . '></label>';
        if ($action === 'login') { $fields .= '<label class="ls-check"><input type="checkbox" name="ls_remember" value="1"> Remember me</label>'; }
        return '<form class="ls-auth-form" method="post" action="' . $url . '">' . $hidden . $fields . '<button class="ls-button" type="submit">' . ($action === 'register' ? 'Create account' : 'Enter the Strategium') . '</button></form>';
    }
}
