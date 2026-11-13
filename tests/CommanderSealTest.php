<?php
use PHPUnit\Framework\TestCase;
final class CommanderSealTest extends TestCase {
    public function test_dashboard_uses_commander_identity(): void {
        $plugin = file_get_contents(dirname(__DIR__) . '/includes/class-ls-plugin.php');
        self::assertStringContainsString('LS_Accounts::commander_name((int)$user->ID)', $plugin);
        $accounts = file_get_contents(dirname(__DIR__) . '/includes/class-ls-accounts.php');
        self::assertStringContainsString("get_user_meta(\$id, 'ls_commander_name', true)", $accounts);
    }
    public function test_email_changes_require_confirmation_and_expire(): void {
        $accounts = file_get_contents(dirname(__DIR__) . '/includes/class-ls-accounts.php');
        self::assertStringContainsString("'ls_pending_email'", $accounts);
        self::assertStringContainsString("'ls_email_change_hash'", $accounts);
        self::assertStringContainsString('wp_check_password($token, $hash, $id)', $accounts);
        self::assertStringContainsString('wp_update_user(', $accounts);
        self::assertStringContainsString('DAY_IN_SECONDS', $accounts);
    }
    public function test_password_changes_require_current_password(): void {
        $accounts = file_get_contents(dirname(__DIR__) . '/includes/class-ls-accounts.php');
        self::assertStringContainsString('wp_check_password($current, $user->user_pass, $id)', $accounts);
        self::assertStringContainsString('wp_set_password($new, $id)', $accounts);
        self::assertStringContainsString('wp_logout();', $accounts);
    }
    public function test_discovery_is_opt_in(): void {
        $accounts = file_get_contents(dirname(__DIR__) . '/includes/class-ls-accounts.php');
        self::assertStringContainsString("get_user_meta((int)\$user->ID, 'ls_profile_discoverable', true) === '1'", $accounts);
        self::assertStringContainsString("!empty(\$_POST['ls_discoverable']) ? '1' : '0'", $accounts);
    }
}
