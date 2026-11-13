<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-armoury.php';
final class ArmouryOptionsTest extends TestCase {
    public function test_valid_options_support_equipment_exchanges_and_model_specific_upgrades(): void {
        $result = LS_Armoury::sanitise_unit_options(array(
            array('name'=>'Exchange hammer','type'=>'replace','model'=>'Master','from'=>'hammer','to'=>'blade','points'=>'15','min'=>'0','max'=>'1'),
            array('name'=>'Extra Master','type'=>'model','model'=>'Master','from'=>'','to'=>'','points'=>'40','min'=>'0','max'=>'2'),
        ), array('hammer','blade'), array('Master'));
        self::assertCount(2, $result);
        self::assertSame('blade', $result[0]['to']);
        self::assertSame(15, $result[0]['points']);
        self::assertSame('model', $result[1]['type']);
    }
    public function test_options_reject_cross_release_wargear_and_invalid_limits(): void {
        $result = LS_Armoury::sanitise_unit_options(array(
            array('name'=>'Foreign','type'=>'add','model'=>'','from'=>'','to'=>'foreign','points'=>'5','min'=>'0','max'=>'1'),
            array('name'=>'Bad limit','type'=>'add','model'=>'','from'=>'','to'=>'hammer','points'=>'5','min'=>'3','max'=>'1'),
            array('name'=>'Wrong model','type'=>'model','model'=>'Unknown','from'=>'','to'=>'','points'=>'5','min'=>'0','max'=>'1'),
            array('name'=>'Valid','type'=>'add','model'=>'','from'=>'','to'=>'hammer','points'=>'-5','min'=>'0','max'=>'1'),
        ), array('hammer'), array('Master'));
        self::assertCount(1, $result);
        self::assertSame(-5, $result[0]['points']);
    }
    public function test_option_subtotals_validate_selection_limits(): void {
        $options = array(array('points'=>15,'min'=>0,'max'=>2), array('points'=>-5,'min'=>1,'max'=>3));
        self::assertSame(25, LS_Armoury::option_points_total($options, array(0=>2,1=>1)));
        self::assertNull(LS_Armoury::option_points_total($options, array(0=>3)));
        self::assertNull(LS_Armoury::option_points_total($options, array(9=>1)));
    }
}
