<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-muster.php';
final class MusterDetachmentSlotsTest extends TestCase {
    private function detachment(string $id, array $slots): array {
        return array('id'=>$id,'title'=>'Test '.$id,'source_data'=>array('slots'=>$slots));
    }
    private function unit(string $id, string $role, int $quantity): array {
        return array('detachment_id'=>$id,'quantity'=>$quantity,'source_data'=>array('role'=>$role));
    }
    public function test_minimum_and_maximum_are_counted_per_detachment(): void {
        $roster = array('detachments'=>array($this->detachment('a',array(array('role'=>'Troops','min'=>2,'max'=>3))),$this->detachment('b',array(array('role'=>'Troops','min'=>1,'max'=>1)))), 'units'=>array($this->unit('a','Troops',1),$this->unit('b','Troops',2)));
        $review = LS_Muster::detachment_slot_review($roster);
        self::assertCount(1,$review['detachments']['a']['issues']);
        self::assertStringContainsString('at least 2',$review['detachments']['a']['issues'][0]);
        self::assertCount(1,$review['detachments']['b']['issues']);
        self::assertStringContainsString('at most 1',$review['detachments']['b']['issues'][0]);
        self::assertSame(0,$review['unassigned']);
        self::assertFalse($review['fully_validated']);
    }
    public function test_unpermitted_missing_and_orphaned_roles_are_reported(): void {
        $roster = array('detachments'=>array($this->detachment('a',array(array('role'=>'Troops','min'=>0,'max'=>3)))), 'units'=>array($this->unit('a','Elites',1),$this->unit('a','',2),$this->unit('old','Troops',3)));
        $review = LS_Muster::detachment_slot_review($roster);
        self::assertSame(3,$review['unassigned']);
        self::assertCount(2,$review['detachments']['a']['issues']);
        self::assertCount(3,$review['issues']);
    }
    public function test_saved_snapshots_are_used_and_no_slots_are_not_declared_legal(): void {
        $roster = array('detachments'=>array($this->detachment('a',array(array('role'=>'Test Role','min'=>1,'max'=>10))),$this->detachment('b',array())), 'units'=>array($this->unit('a','Test Role',1)));
        $review = LS_Muster::detachment_slot_review($roster);
        self::assertSame(array(),$review['detachments']['a']['issues']);
        self::assertCount(1,$review['detachments']['b']['issues']);
        self::assertTrue($review['slots_checked']);
        self::assertFalse($review['fully_validated']);
    }
}
