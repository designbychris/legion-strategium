<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-muster.php';
final class MusterDetachmentsTest extends TestCase {
    public function test_assignments_are_instance_scoped_and_quantity_aware(): void {
        $roster = array('detachments'=>array(array('id'=>'one','title'=>'Example'),array('id'=>'two','title'=>'Example')), 'units'=>array(
            array('quantity'=>2,'detachment_id'=>'one'),array('quantity'=>1,'detachment_id'=>'two'),array('quantity'=>3)
        ));
        $review = LS_Muster::detachment_review($roster);
        self::assertSame(array('one'=>2,'two'=>1), $review['assigned']);
        self::assertSame(3, $review['unassigned']);
        self::assertFalse($review['fully_validated']);
    }
    public function test_unknown_or_removed_detachment_does_not_count_as_assigned(): void {
        $review = LS_Muster::detachment_review(array('units'=>array(array('quantity'=>1,'detachment_id'=>'old'))));
        self::assertSame(array(), $review['assigned']);
        self::assertSame(1, $review['unassigned']);
    }
    public function test_admin_post_and_release_scoped_catalogue_are_wired(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-ls-muster.php');
        self::assertStringContainsString("admin_post_ls_muster_detachment", $source);
        self::assertStringContainsString("check_admin_referer('ls_muster_detachment')", $source);
        self::assertStringContainsString("WHERE release_id = %d ORDER BY title, id", $source);
        self::assertStringContainsString("'user_id'=>get_current_user_id()", $source);
    }
}
