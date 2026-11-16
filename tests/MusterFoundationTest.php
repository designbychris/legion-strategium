<?php
use PHPUnit\Framework\TestCase;
final class MusterFoundationTest extends TestCase {
    public function test_muster_bootstraps_with_plugin(): void {
        $plugin = file_get_contents(dirname(__DIR__) . '/includes/class-ls-plugin.php');
        $entry = file_get_contents(dirname(__DIR__) . '/legion-strategium.php');
        self::assertStringContainsString('LS_Muster::boot()', $plugin);
        self::assertStringContainsString('LS_Muster::render($return)', $plugin);
        self::assertStringContainsString("includes/class-ls-muster.php", $entry);
    }
    public function test_muster_requires_ownership_and_nonce(): void {
        $php = file_get_contents(dirname(__DIR__) . '/includes/class-ls-muster.php');
        self::assertStringContainsString("check_admin_referer('ls_muster_create')", $php);
        self::assertStringContainsString("check_admin_referer('ls_muster_rename')", $php);
        self::assertStringContainsString("'user_id'=>get_current_user_id()", $php);
        self::assertStringContainsString("'user_id'=>get_current_user_id()", $php);
        self::assertStringContainsString('WHERE a.user_id = %d', $php);
    }
    public function test_muster_is_release_and_faction_scoped(): void {
        $php = file_get_contents(dirname(__DIR__) . '/includes/class-ls-muster.php');
        self::assertStringContainsString("r.status = 'published'", $php);
        self::assertStringContainsString("e.status = 'published'", $php);
        self::assertStringContainsString('self::valid_faction($release_id, $faction_id)', $php);
        self::assertStringContainsString("'units'=>array()", $php);
        self::assertStringContainsString('data-release=', $php);
    }
}
