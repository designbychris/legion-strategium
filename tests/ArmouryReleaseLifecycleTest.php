<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-armoury.php';
final class ArmouryReleaseLifecycleTest extends TestCase {
    public function test_only_unused_published_releases_can_return_to_draft(): void {
        self::assertTrue(LS_Armoury::release_transition_allowed(12, 'draft', 'published', false));
        self::assertFalse(LS_Armoury::release_transition_allowed(12, 'draft', 'published', true));
        self::assertFalse(LS_Armoury::release_transition_allowed(12, 'draft', 'retired', false));
    }
    public function test_retirement_and_restoration_keep_existing_armies(): void {
        self::assertTrue(LS_Armoury::release_transition_allowed(12, 'retired', 'published', true));
        self::assertTrue(LS_Armoury::release_transition_allowed(12, 'published', 'retired', true));
        self::assertFalse(LS_Armoury::release_transition_allowed(12, 'published', 'draft', false));
    }
    public function test_invalid_requests_fail_before_database_access(): void {
        self::assertFalse(LS_Armoury::transition_release_data(0, 'draft'));
        self::assertFalse(LS_Armoury::transition_release_data(2, 'deleted'));
        self::assertFalse(LS_Armoury::release_transition_allowed(-1, 'retired', 'published', false));
    }
    public function test_admin_has_nonce_protected_transition_and_keeps_clone(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-ls-armoury.php');
        self::assertStringContainsString("check_admin_referer('ls_armoury_release_transition')", $source);
        self::assertStringContainsString("'status'=>\$status", $source);
        self::assertStringContainsString("'ls_armoury_clone'", $source);
    }
}
