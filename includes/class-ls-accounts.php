<?php
if (!defined('ABSPATH')) { exit; }
final class LS_Accounts {
    public static function boot(): void {
        add_action('template_redirect', array(__CLASS__, 'verify_link'));
        foreach (array('login','register','logout','profile','resend') as $action) {
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
        if (!self::allow_attempt('login', 12, 15 * MINUTE_IN_SECONDS)) { self::redirect('rate_limited', $url); }
        $username = isset($_POST['ls_identity']) ? sanitize_text_field(wp_unslash($_POST['ls_identity'])) : '';
        $password = isset($_POST['ls_password']) && is_string($_POST['ls_password']) ? wp_unslash($_POST['ls_password']) : '';
        $user = wp_signon(array('user_login' => $username, 'user_password' => $password, 'remember' => !empty($_POST['ls_remember'])), is_ssl());
        if (is_wp_error($user)) { self::redirect('invalid_login', $url); }
        if (!self::is_verified((int)$user->ID)) { wp_logout(); self::redirect('verify_required', $url); }
        self::redirect('signed_in', $url);
    }
    public static function register(): void {
        $url = self::return_url();
        if (!self::verify('register')) { self::redirect('expired', $url); }
        if (is_user_logged_in()) { self::redirect('signed_in', $url); }
        if (!get_option('users_can_register')) { self::redirect('closed', $url); }
        if (!self::allow_attempt('register', 5, HOUR_IN_SECONDS)) { self::redirect('rate_limited', $url); }
        if (!empty($_POST['ls_website'])) { self::redirect('registered', $url); }
        $name = isset($_POST['ls_username']) ? sanitize_user(wp_unslash($_POST['ls_username']), true) : '';
        $email = isset($_POST['ls_email']) ? sanitize_email(wp_unslash($_POST['ls_email'])) : '';
        $password = isset($_POST['ls_password']) && is_string($_POST['ls_password']) ? wp_unslash($_POST['ls_password']) : '';
        if (!$name || !validate_username($name) || !is_email($email) || strlen($password) < 12 || strlen($password) > 4096) { self::redirect('invalid_registration', $url); }
        if (username_exists($name) || email_exists($email)) { self::redirect('unavailable', $url); }
        // Fixed subscriber role: never accept roles/capabilities from a public request.
        $id = wp_insert_user(array('user_login' => $name, 'user_email' => $email, 'user_pass' => $password, 'role' => 'subscriber'));
        if (is_wp_error($id)) { self::redirect('invalid_registration', $url); }
        update_user_meta($id, 'ls_verified', '0');
        update_user_meta($id, 'ls_commander_name', $name);
        self::send_verification((int)$id, $url);
        self::redirect('registered', $url);
    }
    public static function logout(): void {
        $url = self::return_url();
        if (!self::verify('logout') || !is_user_logged_in()) { self::redirect('expired', $url); }
        wp_logout();
        self::redirect('signed_out', $url);
    }
    public static function notice(): string {
        $messages = array('verify_required' => 'Please verify your email before signing in. You can request another verification email below.', 'verified' => 'Email verified. You can now sign in.', 'verification_invalid' => 'That verification link is invalid or has expired.', 'verification_sent' => 'If that address has a pending account, a verification email has been sent.', 'rate_limited' => 'Too many requests. Please try again later.', 'profile_saved' => 'Commander profile updated.', 'profile_invalid' => 'Please enter a valid commander name.', 'expired' => 'Your session expired. Please try again.', 'invalid_login' => 'Sign-in failed. Please check your credentials.', 'signed_in' => 'Welcome to the Strategium.', 'registered' => 'Account created. Check your email for a verification link before signing in.', 'closed' => 'New registrations are currently closed.', 'invalid_registration' => 'Please provide a valid commander name, email, and password of at least 12 characters.', 'unavailable' => 'That commander name or email is unavailable.', 'signed_out' => 'You have signed out.');
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
        if ($action === 'register') { $fields .= '<div class="ls-trap" aria-hidden="true"><label>Website<input tabindex="-1" autocomplete="off" name="ls_website"></label></div>'; }
        $fields .= '<label>Password<input required type="password" name="ls_password" autocomplete="' . ($action === 'register' ? 'new-password' : 'current-password') . '" ' . ($action === 'register' ? 'minlength="12"' : '') . '></label>';
        if ($action === 'login') { $fields .= '<label class="ls-check"><input type="checkbox" name="ls_remember" value="1"> Remember me</label>'; }
        return '<form class="ls-auth-form" method="post" action="' . $url . '">' . $hidden . $fields . '<button class="ls-button" type="submit">' . ($action === 'register' ? 'Create account' : 'Enter the Strategium') . '</button></form>';
    }
    public static function is_verified(int $id): bool {
        // Existing WordPress accounts predate this gate; never lock them out.
        return get_user_meta($id, 'ls_verified', true) !== '0';
    }
    public static function allow_attempt(string $action, int $limit, int $period): bool {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : 'unknown';
        $key = 'ls_rate_' . hash('sha256', $action . '|' . $ip);
        $count = (int)get_transient($key);
        if ($count >= $limit) { return false; }
        set_transient($key, $count + 1, $period);
        return true;
    }
    public static function send_verification(int $id, string $return): bool {
        $user = get_userdata($id);
        if (!$user || self::is_verified($id)) { return false; }
        $token = bin2hex(random_bytes(32));
        update_user_meta($id, 'ls_verify_hash', wp_hash_password($token));
        update_user_meta($id, 'ls_verify_expires', time() + DAY_IN_SECONDS);
        $link = add_query_arg(array('ls_verify' => $id, 'ls_token' => $token), $return);
        return wp_mail($user->user_email, 'Verify your Legion Strategium account', "Welcome to the Legion Strategium.\n\nVerify your email within 24 hours:\n" . $link . "\n\nIf you did not register, ignore this message.");
    }
    public static function verify_link(): void {
        if (!isset($_GET['ls_verify'], $_GET['ls_token'])) { return; }
        $id = absint($_GET['ls_verify']);
        $token = sanitize_text_field(wp_unslash($_GET['ls_token']));
        $hash = (string)get_user_meta($id, 'ls_verify_hash', true);
        $expiry = (int)get_user_meta($id, 'ls_verify_expires', true);
        $code = 'verification_invalid';
        if ($id && $hash && $expiry >= time() && strlen($token) === 64 && wp_check_password($token, $hash, $id)) {
            update_user_meta($id, 'ls_verified', '1');
            delete_user_meta($id, 'ls_verify_hash');
            delete_user_meta($id, 'ls_verify_expires');
            $code = 'verified';
        }
        wp_safe_redirect(add_query_arg('ls_notice', $code, remove_query_arg(array('ls_verify','ls_token'))));
        exit;
    }
    public static function resend(): void {
        $url = self::return_url();
        if (!self::verify('resend')) { self::redirect('expired', $url); }
        if (!self::allow_attempt('resend', 3, HOUR_IN_SECONDS)) { self::redirect('rate_limited', $url); }
        $email = isset($_POST['ls_email']) ? sanitize_email(wp_unslash($_POST['ls_email'])) : '';
        $user = get_user_by('email', $email);
        if ($user && !self::is_verified((int)$user->ID)) { self::send_verification((int)$user->ID, $url); }
        self::redirect('verification_sent', $url);
    }
    public static function profile(): void {
        $url = self::return_url();
        if (!self::verify('profile') || !is_user_logged_in()) { self::redirect('expired', $url); }
        $id = get_current_user_id();
        $name = isset($_POST['ls_commander_name']) ? sanitize_text_field(wp_unslash($_POST['ls_commander_name'])) : '';
        if (strlen($name) < 3 || strlen($name) > 60) { self::redirect('profile_invalid', $url); }
        update_user_meta($id, 'ls_commander_name', $name);
        self::redirect('profile_saved', $url);
    }
    public static function profile_form(string $return): string {
        $name = (string)get_user_meta(get_current_user_id(), 'ls_commander_name', true);
        if ($name === '') { $name = wp_get_current_user()->display_name; }
        return '<div class="ls-profile"><h3>Commander Profile</h3><form class="ls-auth-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_profile"><input type="hidden" name="ls_return" value="' . esc_attr($return) . '">' . wp_nonce_field('ls_profile', 'ls_nonce', true, false) . '<label>Public commander name<input required minlength="3" maxlength="60" name="ls_commander_name" value="' . esc_attr($name) . '"></label><button class="ls-button" type="submit">Save profile</button></form><p class="ls-muted">Your email and password can be managed through your WordPress account settings for now.</p></div>';
    }
    public static function resend_form(string $return): string {
        return '<form class="ls-auth-form ls-resend" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ls_resend"><input type="hidden" name="ls_return" value="' . esc_attr($return) . '">' . wp_nonce_field('ls_resend', 'ls_nonce', true, false) . '<label>Resend verification email<input type="email" name="ls_email" required autocomplete="email"></label><button class="ls-button ls-secondary" type="submit">Resend verification</button></form>';
    }

}
