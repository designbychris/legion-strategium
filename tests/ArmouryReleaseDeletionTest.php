<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-armoury.php';
final class ArmouryReleaseDeletionTest extends TestCase {
    public function test_release_deletion_exposes_preview_and_handler(): void {
        self::assertTrue(method_exists(LS_Armoury::class, 'release_deletion_counts'));
        self::assertTrue(method_exists(LS_Armoury::class, 'delete_release'));
    }
    public function test_invalid_release_ids_are_rejected_without_database_access(): void {
        self::assertFalse(LS_Armoury::release_can_be_deleted(0));
        self::assertFalse(LS_Armoury::release_can_be_deleted(-1));
        self::assertFalse(LS_Armoury::delete_release_data(0, '2026-2'));
        self::assertFalse(LS_Armoury::delete_release_data(2, ''));
    }
    public function test_release_deletion_is_scoped_and_requires_confirmation(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-ls-armoury.php');
        self::assertStringContainsString('array(\'release_id\'=>$id)', $source);
        self::assertStringContainsString("'status'=>'draft'", $source);
        self::assertStringContainsString('hash_equals((string)$release->version, $expected_version)', $source);
        self::assertStringContainsString("$" . "wpdb->query('ROLLBACK')", $source);
    }
}
