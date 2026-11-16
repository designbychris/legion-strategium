<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-muster.php';
final class MusterQuartermasterTest extends TestCase {
    private function entry(): array {
        return array('base_points'=>250,'quantity'=>2,'source_data'=>array('unit_options'=>array(
            array('name'=>'Extra Firedrake','type'=>'model','model'=>'Firedrake','points'=>35,'min'=>0,'max'=>4),
            array('name'=>'Hammer exchange','type'=>'replace','points'=>10,'min'=>0,'max'=>1)
        )));
    }
    public function test_snapshot_options_add_to_points_without_catalogue_lookup(): void {
        $entry=$this->entry();$entry['choices']=array(0=>2,1=>1);
        self::assertSame(660, LS_Muster::entry_points($entry));
        self::assertSame(660, LS_Muster::roster_points(array('units'=>array($entry))));
    }
    public function test_choices_are_validated_against_snapshot_limits(): void {
        $entry=$this->entry();
        self::assertSame(array(0=>2,1=>1),LS_Muster::validated_choices($entry,array(0=>'2',1=>'1')));
        self::assertFalse(LS_Muster::validated_choices($entry,array(0=>'5',1=>'0')));
        self::assertFalse(LS_Muster::validated_choices($entry,array(2=>'1')));
    }
    public function test_unconfigured_legacy_rosters_keep_base_points(): void {
        self::assertSame(500,LS_Muster::entry_points($this->entry()));
        self::assertSame(750,LS_Muster::roster_points(array('units'=>array(array('base_points'=>250,'quantity'=>3)))));
    }
}
