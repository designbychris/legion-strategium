<?php
use PHPUnit\Framework\TestCase;
final class ArmouryLedgerTest extends TestCase {
    public function test_draft_management_is_registered_and_protected(): void {
        $src = file_get_contents(dirname(__DIR__) . '/includes/class-ls-armoury.php');
        $this->assertStringContainsString("admin_post_ls_armoury_manage", $src);
        $this->assertStringContainsString("check_admin_referer('ls_armoury_manage')", $src);
        $this->assertStringContainsString("current_user_can('manage_options')", $src);
        $this->assertStringContainsString('self::release_is_draft((int)$record->release_id)', $src);
    }
    public function test_reassignment_must_match_existing_release(): void {
        $src = file_get_contents(dirname(__DIR__) . '/includes/class-ls-armoury.php');
        $this->assertStringContainsString('self::faction_in_release($faction, (int)$record->release_id)', $src);
        $this->assertStringContainsString('self::release_has_armies((int)$record->release_id)', $src);
    }
    public function test_delete_protects_factions_with_units(): void {
        $src = file_get_contents(dirname(__DIR__) . '/includes/class-ls-armoury.php');
        $this->assertStringContainsString('SELECT COUNT(*) FROM ', $src);
        $this->assertStringContainsString('WHERE faction_id = %d', $src);
        $this->assertStringContainsString('Permanently delete this draft record?', $src);
    }
}
