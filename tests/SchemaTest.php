<?php
use PHPUnit\Framework\TestCase;
final class SchemaTest extends TestCase {
    public function test_all_expected_tables_exist(): void {
        $sql = implode("\n", LS_Schema::definitions('wp_', 'DEFAULT CHARSET=utf8mb4'));
        foreach (array('editions','releases','factions','units','detachments','wargear','armies','battles') as $name) {
            self::assertStringContainsString('wp_ls_' . $name, $sql);
        }
    }
    public function test_armies_pin_a_release_and_owner(): void {
        $sql = implode("\n", LS_Schema::definitions('wp_', 'DEFAULT CHARSET=utf8mb4'));
        self::assertMatchesRegularExpression('/CREATE TABLE wp_ls_armies .*user_id .*release_id .*roster/s', $sql);
    }
    public function test_releases_are_edition_scoped(): void {
        $sql = implode("\n", LS_Schema::definitions('wp_', 'DEFAULT CHARSET=utf8mb4'));
        self::assertStringContainsString('UNIQUE KEY edition_version (edition_id,version)', $sql);
    }
}
