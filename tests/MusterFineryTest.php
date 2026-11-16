<?php
if (!defined('ABSPATH')) { exit; }
/** II.5C.2: visual regression contracts for the front-end Muster. */
final class MusterFineryTest extends \PHPUnit\Framework\TestCase {
    private function stylesheet(): string {
        return (string) file_get_contents(dirname(__DIR__) . '/assets/strategium.css');
    }
    public function test_button_contrast_overrides_are_scoped_to_muster(): void {
        $css = $this->stylesheet();
        $this->assertStringContainsString('.ls-shell .ls-muster form button.ls-button', $css);
        $this->assertStringContainsString('color:#201a14!important', $css);
        $this->assertStringContainsString('color:#fff0d5!important', $css);
        $this->assertStringContainsString('opacity:1!important', $css);
    }
    public function test_roster_and_form_layouts_have_responsive_rules(): void {
        $css = $this->stylesheet();
        $this->assertStringContainsString('.ls-shell .ls-muster-form', $css);
        $this->assertStringContainsString('.ls-shell .ls-roster-entry', $css);
        $this->assertStringContainsString('.ls-shell .ls-roster-config', $css);
        $this->assertStringContainsString('@media(max-width:520px)', $css);
    }
    public function test_quartermaster_message_is_current(): void {
        $php = (string) file_get_contents(dirname(__DIR__) . '/includes/class-ls-muster.php');
        $this->assertStringNotContainsString('Model additions and upgrades arrive in II.5C.', $php);
        $this->assertStringContainsString('Unit points include saved Quartermaster selections.', $php);
    }
}
