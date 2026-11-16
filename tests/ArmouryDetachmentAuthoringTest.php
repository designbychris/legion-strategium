<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-armoury.php';
final class ArmouryDetachmentAuthoringTest extends TestCase {
    public function test_detachment_chamber_is_allowlisted(): void {
        self::assertSame('detachments', LS_Armoury::admin_chamber(array('ls_chamber'=>'detachments')));
        self::assertSame('units', LS_Armoury::admin_chamber(array('ls_chamber'=>'untrusted')));
    }
    public function test_detachment_slots_are_structured_and_bounded(): void {
        $rows = array(array('role'=>'Troops', 'min'=>'1', 'max'=>'4'), array('role'=>'', 'min'=>'0', 'max'=>'1'));
        self::assertSame(array(array('role'=>'Troops', 'min'=>1, 'max'=>4)), LS_Armoury::sanitise_detachment_slots($rows));
        self::assertSame(array(), LS_Armoury::sanitise_detachment_slots(array(array('role'=>'Troops', 'min'=>5, 'max'=>2))));
        self::assertSame(array(), LS_Armoury::sanitise_detachment_slots('invalid'));
    }
    public function test_detachment_authoring_is_nonce_protected_and_draft_scoped(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-ls-armoury.php');
        self::assertStringContainsString("check_admin_referer('ls_detachment_save')", $source);
        self::assertStringContainsString('self::detachment_editable($release)', $source);
        self::assertStringContainsString('!self::release_has_armies($release_id)', $source);
        self::assertStringContainsString('Add detachment definition', $source);
    }
    public function test_frontend_unit_heading_and_buttons_have_scoped_styles(): void {
        $muster = file_get_contents(dirname(__DIR__) . '/includes/class-ls-muster.php');
        $css = file_get_contents(dirname(__DIR__) . '/assets/strategium.css');
        self::assertStringContainsString('ls-roster-unit-heading', $muster);
        self::assertStringContainsString('.ls-muster .ls-roster-unit-heading', $css);
        self::assertStringContainsString('button.ls-button:disabled', $css);
    }
}
