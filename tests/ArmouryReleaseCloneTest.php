<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-armoury.php';
final class ArmouryReleaseCloneTest extends TestCase {
    public function test_clone_handler_and_data_method_exist(): void {
        self::assertTrue(method_exists(LS_Armoury::class, 'clone_release'));
        self::assertTrue(method_exists(LS_Armoury::class, 'clone_release_data'));
    }
    public function test_clone_rejects_invalid_versions_before_database_access(): void {
        self::assertFalse(LS_Armoury::clone_release_data(0, 'draft-2'));
        self::assertFalse(LS_Armoury::clone_release_data(1, ''));
        self::assertFalse(LS_Armoury::clone_release_data(1, 'invalid version'));
        self::assertFalse(LS_Armoury::clone_release_data(1, str_repeat('a', 81)));
    }
}
