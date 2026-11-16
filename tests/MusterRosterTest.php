<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . "/includes/class-ls-muster.php";
final class MusterRosterTest extends TestCase {
    public function test_roster_is_snapshot_not_catalogue_mutation(): void {
        $php = file_get_contents(dirname(__DIR__) . '/includes/class-ls-muster.php');
        self::assertStringContainsString("'source_data'=>is_array(" . '$data' . ") ? " . '$data' . " : array()", $php);
        self::assertStringContainsString("'catalogue_unit_id'=>(int)" . '$source->id', $php);
        self::assertStringContainsString("'entry_id'=>wp_generate_uuid4()", $php);
        self::assertStringContainsString("self::table('armies')", $php);
    }
    public function test_roster_mutations_require_owner_and_nonce(): void {
        $php = file_get_contents(dirname(__DIR__) . '/includes/class-ls-muster.php');
        self::assertStringContainsString("check_admin_referer('ls_muster_unit')", $php);
        self::assertStringContainsString("WHERE id = %d AND user_id = %d", $php);
        self::assertStringContainsString("array('id'=>" . '$army_id' . ", 'user_id'=>get_current_user_id())", $php);
    }
    public function test_roster_points_are_quantity_times_base_cost(): void {
        self::assertSame(750, LS_Muster::roster_points(array('units'=>array(array('base_points'=>250,'quantity'=>3)))));
        self::assertSame(0, LS_Muster::roster_points(array('units'=>array())));
    }
    public function test_roster_is_release_and_faction_scoped(): void {
        $php = file_get_contents(dirname(__DIR__) . '/includes/class-ls-muster.php');
        self::assertStringContainsString('WHERE release_id = %d AND faction_id = %d', $php);
        self::assertStringContainsString("array('add', 'quantity', 'remove', 'configure')", $php);
    }
}
