<?php
use PHPUnit\Framework\TestCase;
final class CommanderIdentityTest extends TestCase {
    public function test_registration_is_fixed_to_subscriber(): void {
        $code = file_get_contents(dirname(__DIR__) . '/includes/class-ls-accounts.php');
        self::assertStringContainsString("'role' => 'subscriber'", $code);
    }
    public function test_verification_token_is_hashed_and_expires(): void {
        $code = file_get_contents(dirname(__DIR__) . '/includes/class-ls-accounts.php');
        self::assertStringContainsString('wp_hash_password($token)', $code);
        self::assertStringContainsString('DAY_IN_SECONDS', $code);
        self::assertStringContainsString('wp_check_password($token, $hash, $id)', $code);
    }
    public function test_authentication_and_registration_are_rate_limited(): void {
        $code = file_get_contents(dirname(__DIR__) . '/includes/class-ls-accounts.php');
        foreach (array("allow_attempt('login'", "allow_attempt('register'", "allow_attempt('resend'") as $needle) {
            self::assertStringContainsString($needle, $code);
        }
    }
}
