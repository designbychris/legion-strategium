<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-armoury.php';
final class ArmouryOptionsPersistenceTest extends TestCase {
    public function test_valid_exchange_with_distinct_same_release_weapons_is_accepted(): void {
        $input = array(array('name'=>'Power fist exchange','type'=>'replace','model'=>'Master','from'=>'hammer','to'=>'power-fist','points'=>'0','min'=>'0','max'=>'1'));
        self::assertSame('', LS_Armoury::unit_option_error($input, array('hammer','power-fist'), array('Master')));
        self::assertSame('power-fist', LS_Armoury::sanitise_unit_options($input, array('hammer','power-fist'), array('Master'))[0]['to']);
    }
    public function test_missing_name_is_reported_instead_of_silently_discarded(): void {
        $input = array(array('name'=>'','type'=>'add','model'=>'','from'=>'','to'=>'hammer','points'=>'0','min'=>'0','max'=>'1'));
        self::assertStringContainsString('enter an option name', LS_Armoury::unit_option_error($input, array('hammer'), array()));
        self::assertSame(array(), LS_Armoury::sanitise_unit_options($input, array('hammer'), array()));
    }
    public function test_wrong_type_and_cross_release_equipment_report_errors(): void {
        $base = array('name'=>'Exchange','type'=>'add','model'=>'','from'=>'hammer','to'=>'power-fist','points'=>'0','min'=>'0','max'=>'1');
        self::assertStringContainsString('Exchange equipment', LS_Armoury::unit_option_error(array($base), array('hammer','power-fist'), array()));
        $base['type'] = 'replace';
        self::assertStringContainsString('this ruleset release', LS_Armoury::unit_option_error(array($base), array('hammer'), array()));
        self::assertSame('', LS_Armoury::unit_option_error(array(array('name'=>'','type'=>'add','model'=>'','from'=>'','to'=>'','points'=>'0','min'=>'0','max'=>'1')), array('hammer'), array()));
    }
}
