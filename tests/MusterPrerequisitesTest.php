<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-muster.php';
require_once dirname(__DIR__) . '/includes/class-ls-armoury.php';
final class MusterPrerequisitesTest extends TestCase {
    public function test_missing_and_satisfied_prerequisites_use_saved_roster(): void {
        $dependent = array('title'=>'Veterans','slug'=>'veterans','quantity'=>1,'source_data'=>array('unit_prerequisites'=>array(array('slug'=>'commander','min'=>2))));
        $roster = array('units'=>array($dependent));
        self::assertCount(1, LS_Muster::prerequisite_review($roster)['issues']);
        $roster['units'][] = array('slug'=>'commander','quantity'=>2);
        self::assertSame(array(), LS_Muster::prerequisite_review($roster)['issues']);
        self::assertFalse(LS_Muster::prerequisite_review($roster)['fully_validated']);
    }
    public function test_snapshot_isolation_and_invalid_requirements_are_advisory(): void {
        $roster = array('units'=>array(array('title'=>'Guard','slug'=>'guard','quantity'=>1,'source_data'=>array('unit_prerequisites'=>array(array('slug'=>'captain','min'=>1),array('slug'=>'bad slug','min'=>1))))));
        $review = LS_Muster::prerequisite_review($roster);
        self::assertCount(2, $review['issues']);
        self::assertStringContainsString('captain', $review['issues'][0]);
    }
    public function test_authoring_is_bounded_and_deduplicated(): void {
        $raw = array(array('slug'=>'leader','min'=>2),array('slug'=>'leader','min'=>3),array('slug'=>'self','min'=>1),array('slug'=>'bad slug','min'=>1),array('slug'=>'support','min'=>101));
        self::assertSame(array(array('slug'=>'leader','min'=>2)), LS_Armoury::sanitise_unit_prerequisites($raw, 'self'));
        self::assertCount(8, LS_Armoury::sanitise_unit_prerequisites(array_map(static function ($i) { return array('slug'=>'unit-'.$i,'min'=>1); }, range(1, 12))));
    }
}
