<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-muster.php';
final class MusterPointsReviewTest extends TestCase {
    private function roster(): array {
        return array('points_allowance'=>500, 'units'=>array(array('entry_id'=>'a', 'title'=>'Firedrakes', 'base_points'=>250, 'quantity'=>2)));
    }
    public function test_points_allowance_and_remaining_are_calculated_without_modifying_roster(): void {
        $roster = $this->roster();
        $review = LS_Muster::muster_review($roster);
        self::assertSame(500, $review['total']);
        self::assertSame(500, $review['allowance']);
        self::assertSame(0, $review['remaining']);
        self::assertSame(array(), $review['issues']);
        self::assertSame(2, $roster['units'][0]['quantity']);
    }
    public function test_overspend_is_advisory_and_does_not_change_saved_choices(): void {
        $roster = $this->roster();
        $roster['points_allowance'] = 400;
        $review = LS_Muster::muster_review($roster);
        self::assertSame(-100, $review['remaining']);
        self::assertContains('Over the points allowance by 100 pts.', $review['issues']);
        self::assertSame(500, LS_Muster::roster_points($roster));
    }
    public function test_empty_or_malformed_roster_is_flagged_without_claiming_full_legality(): void {
        $empty = LS_Muster::muster_review(array('units'=>array()));
        self::assertContains('No units have been added yet.', $empty['issues']);
        self::assertNull($empty['remaining']);
        $invalid = LS_Muster::muster_review(array('units'=>array(array('quantity'=>0))));
        self::assertNotEmpty($invalid['issues']);
        self::assertSame(0, LS_Muster::points_allowance(array('points_allowance'=>'invalid')));
    }
}
