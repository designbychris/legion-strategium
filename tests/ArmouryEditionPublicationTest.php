<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-armoury.php';
final class ArmouryEditionPublicationTest extends TestCase {
    public function test_draft_edition_can_be_published(): void {
        self::assertTrue(LS_Armoury::edition_transition_allowed('draft', 'published', true, false));
        self::assertFalse(LS_Armoury::edition_transition_allowed('published', 'published', false, false));
    }
    public function test_used_or_published_edition_cannot_be_unpublished(): void {
        self::assertTrue(LS_Armoury::edition_transition_allowed('published', 'draft', false, false));
        self::assertFalse(LS_Armoury::edition_transition_allowed('published', 'draft', true, false));
        self::assertFalse(LS_Armoury::edition_transition_allowed('published', 'draft', false, true));
        self::assertFalse(LS_Armoury::edition_transition_allowed('draft', 'draft', false, false));
    }
    public function test_edition_controls_are_nonce_protected(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-ls-armoury.php');
        self::assertStringContainsString("check_admin_referer('ls_armoury_edition_transition')", $source);
        self::assertStringContainsString('Publish edition', $source);
        self::assertStringContainsString('Existing editions', $source);
    }
}
