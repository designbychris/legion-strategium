<?php
use PHPUnit\Framework\TestCase;
final class ArmouryTest extends TestCase {
    public function test_catalogue_class_is_available(): void {
        require_once dirname(__DIR__) . '/includes/class-ls-armoury.php';
        $this->assertTrue(class_exists('LS_Armoury'));
        $this->assertTrue(method_exists('LS_Armoury', 'catalogue'));
        $this->assertTrue(method_exists('LS_Armoury', 'save'));
    }
    public function test_schema_contains_release_scoped_catalogue(): void {
        $sql = implode("\n", LS_Schema::definitions('wp_', ''));
        $this->assertStringContainsString('ls_editions', $sql);
        $this->assertStringContainsString('ls_releases', $sql);
        $this->assertStringContainsString('ls_factions', $sql);
        $this->assertStringContainsString('ls_units', $sql);
        $this->assertStringContainsString('UNIQUE KEY release_faction_slug', $sql);
    }
    public function test_admin_write_path_checks_capability_and_nonce(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-ls-armoury.php');
        $this->assertStringContainsString("current_user_can('manage_options')", $source);
        $this->assertStringContainsString("check_admin_referer('ls_armoury_save')", $source);
        $this->assertStringContainsString('self::faction_in_release($faction, $release)', $source);
    }
    public function test_catalogue_is_connected_to_commander_dashboard(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-ls-plugin.php');
        $this->assertStringContainsString('LS_Armoury::catalogue()', $source);
        $this->assertStringContainsString('LS_Armoury::admin()', $source);
    }
}
