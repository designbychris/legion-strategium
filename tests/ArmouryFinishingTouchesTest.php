<?php
use PHPUnit\Framework\TestCase;
final class ArmouryFinishingTouchesTest extends TestCase {
    public function test_imperial_palette_and_admin_scoping(): void {
        $css = file_get_contents(dirname(__DIR__) . '/assets/armoury-admin.css');
        self::assertStringContainsString('--ls-ink:#201d19', $css);
        self::assertStringContainsString('--ls-gold:#c9a052', $css);
        self::assertStringContainsString('--ls-crimson:#852e32', $css);
        self::assertStringContainsString('.ls-armoury-admin .ls-workbench-tab.is-active', $css);
    }
    public function test_responsive_and_accessible_controls_are_styled(): void {
        $css = file_get_contents(dirname(__DIR__) . '/assets/armoury-admin.css');
        self::assertStringContainsString('@media(max-width:600px)', $css);
        self::assertStringContainsString('@media(prefers-reduced-motion:reduce)', $css);
        self::assertStringContainsString('button:focus-visible', $css);
        self::assertStringContainsString('[data-ls-panel="models"] .ls-profile-grid', $css);
    }
}
