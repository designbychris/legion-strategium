<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-armoury.php';
final class ArmouryWorkbenchTest extends TestCase {
    public function test_workbench_has_all_six_sections(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-ls-armoury.php');
        foreach (array('overview', 'models', 'composition', 'wargear', 'upgrades', 'rules') as $section) {
            self::assertStringContainsString('data-ls-panel="' . $section . '"', $source);
        }
    }
    public function test_workbench_keeps_existing_post_field_names(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-ls-armoury.php');
        foreach (array('ls_model_profiles[', 'ls_composition[', 'ls_equipment_links[', 'ls_unit_options[', 'ls_profile[', 'ls_rule_links[]') as $field) {
            self::assertStringContainsString($field, $source);
        }
    }
    public function test_tabs_are_progressive_enhancement(): void {
        $js = file_get_contents(dirname(__DIR__) . '/assets/datasheet-editor.js');
        self::assertStringContainsString("activate('overview')", $js);
        self::assertStringContainsString("addEventListener('invalid'", $js);
        self::assertStringContainsString('panel.hidden', $js);
    }
}
