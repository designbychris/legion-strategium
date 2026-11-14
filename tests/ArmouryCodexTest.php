<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-armoury.php';
final class ArmouryCodexTest extends TestCase {
    public function test_rule_payload_sanitises_type_and_markup(): void {
        $rule = LS_Armoury::sanitise_rule(array('type'=>'trait','description'=>'<b>Example</b> description'));
        self::assertSame('trait', $rule['type']);
        self::assertSame('Example description', $rule['description']);
        self::assertSame('special', LS_Armoury::sanitise_rule(array('type'=>'unknown'))['type']);
    }
    public function test_links_only_allow_known_release_slugs_and_deduplicate(): void {
        self::assertSame(array('breaching','brutal'), LS_Armoury::sanitise_rule_links(array('breaching','other-release','brutal','breaching'), array('breaching','brutal')));
        self::assertSame(array(), LS_Armoury::sanitise_rule_links('invalid', array('breaching')));
    }
    public function test_schema_and_clone_include_rules(): void {
        $definitions = LS_Schema::definitions('wp_', '');
        self::assertTrue((bool)array_filter($definitions, static function ($sql) { return strpos($sql, 'wp_ls_rules') !== false; }));
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-ls-armoury.php');
        self::assertStringContainsString("array('detachments','wargear','rules')", $source);
    }
}
