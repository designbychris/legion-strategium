<?php
use PHPUnit\Framework\TestCase;
// This structural test runs without bootstrapping WordPress.
if (!function_exists('absint')) {
    function absint($value): int {
        return abs((int) $value);
    }
}
require_once dirname(__DIR__) . '/includes/class-ls-armoury.php';
final class ArmouryBrowserTest extends TestCase {
    public function test_browse_context_whitelists_numeric_filters(): void {
        $result = LS_Armoury::browse_context(array('ls_edition'=>'2','ls_release'=>'7','ls_faction'=>'11','ls_page'=>'3','redirect_to'=>'https://evil.example'));
        self::assertSame(array('ls_edition'=>2,'ls_release'=>7,'ls_faction'=>11,'ls_page'=>3), $result);
    }
    public function test_browse_context_ignores_unrecognised_parameters(): void {
        self::assertSame(array(), LS_Armoury::browse_context(array('ls_edit_unit'=>99, 'action'=>'delete')));
    }
    public function test_catalogue_browser_and_editor_are_present(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-ls-armoury.php');
        self::assertStringContainsString('ls-catalogue-filters', $source);
        self::assertStringContainsString('ls_edit_unit', $source);
        self::assertStringContainsString('array_slice($visible_units', $source);
    }
}
