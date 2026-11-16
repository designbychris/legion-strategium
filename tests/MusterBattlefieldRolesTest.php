<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-muster.php';
final class MusterBattlefieldRolesTest extends TestCase {
    public function test_counts_roles_from_saved_snapshot_and_quantity(): void {
        $roster = array('units'=>array(
            array('quantity'=>2,'source_data'=>array('role'=>'Elites')),
            array('quantity'=>1,'source_data'=>array('role'=>'Troops')),
            array('quantity'=>3,'source_data'=>array('role'=>'Elites')),
        ));
        $summary = LS_Muster::battlefield_role_summary($roster);
        self::assertSame(array('Elites'=>5,'Troops'=>1),$summary['roles']);
        self::assertSame(0,$summary['unassigned']);
        self::assertSame(3,$roster['units'][2]['quantity']);
    }
    public function test_missing_roles_are_advisory_and_do_not_invent_legality(): void {
        $review = LS_Muster::composition_review(array('units'=>array(
            array('quantity'=>1,'source_data'=>array('role'=>'')),
            array('quantity'=>2),
            array('quantity'=>1,'source_data'=>array('role'=>'Heavy Support')),
        )));
        self::assertSame(3,$review['summary']['unassigned']);
        self::assertSame(array('Heavy Support'=>1),$review['summary']['roles']);
        self::assertNotEmpty($review['issues']);
        self::assertFalse($review['limits_configured']);
        self::assertFalse($review['fully_validated']);
    }
    public function test_malformed_rows_do_not_create_false_role_slots(): void {
        $summary = LS_Muster::battlefield_role_summary(array('units'=>array(
            'invalid',
            array('quantity'=>0,'source_data'=>array('role'=>'Troops')),
            array('quantity'=>101,'source_data'=>array('role'=>'Elites')),
        )));
        self::assertSame(array(),$summary['roles']);
        self::assertSame(0,$summary['unassigned']);
    }
}
