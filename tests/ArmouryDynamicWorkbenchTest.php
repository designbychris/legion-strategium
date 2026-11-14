<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-armoury.php';
final class ArmouryDynamicWorkbenchTest extends TestCase {
    public function test_all_dynamic_lists_have_controls_and_limits(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-ls-armoury.php');
        foreach (array('models'=>'20', 'wargear'=>'40', 'upgrades'=>'30') as $name=>$limit) {
            self::assertStringContainsString('data-ls-list="' . $name . '" data-ls-limit="' . $limit . '"', $source);
            self::assertStringContainsString('data-ls-add="' . $name . '"', $source);
        }
    }
    public function test_empty_submissions_are_explicitly_preserved(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-ls-armoury.php');
        foreach (array('ls_model_profiles', 'ls_equipment_links', 'ls_unit_options') as $field) {
            self::assertStringContainsString("isset(\$_POST['" . $field . "_present'])", $source);
            self::assertStringContainsString('name="' . $field . '_present"', $source);
        }
    }
    public function test_dynamic_editor_reindexes_and_refreshes_model_choices(): void {
        $source = file_get_contents(dirname(__DIR__) . '/assets/datasheet-editor.js');
        self::assertStringContainsString('function reindex(kind)', $source);
        self::assertStringContainsString('function refreshModelChoices()', $source);
        self::assertStringContainsString("row.remove()", $source);
    }
}
