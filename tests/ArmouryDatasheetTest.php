<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-armoury.php';
final class ArmouryDatasheetTest extends TestCase {
    public function test_multiple_model_profiles_keep_distinct_statistics(): void {
        $result = LS_Armoury::sanitise_model_profiles(array(
            array('name'=>'Firedrake Master','movement'=>'5','weapon_skill'=>'6','invulnerable_save'=>'4+'),
            array('name'=>'Firedrake','movement'=>'5','weapon_skill'=>'5','cool'=>'9')));
        self::assertCount(2, $result);
        self::assertSame('6', $result[0]['characteristics']['weapon_skill']);
        self::assertSame('5', $result[1]['characteristics']['weapon_skill']);
        self::assertSame('4+', $result[0]['characteristics']['invulnerable_save']);
    }
    public function test_model_profiles_reject_invalid_fields_and_blank_names(): void {
        $result = LS_Armoury::sanitise_model_profiles(array(
            array('name'=>'', 'movement'=>'5'),
            array('name'=>'Firedrake', 'movement'=>'<script>', 'unknown'=>'7', 'save'=>'2+')));
        self::assertCount(1, $result);
        self::assertSame(array('save'=>'2+'), $result[0]['characteristics']);
    }
    public function test_composition_checks_ranges(): void {
        self::assertSame(array(), LS_Armoury::sanitise_composition(array('minimum_models'=>'10','maximum_models'=>'5')));
        self::assertSame(array('minimum_models'=>5,'maximum_models'=>10,'starting_models'=>5,'additional_model_points'=>40),
            LS_Armoury::sanitise_composition(array('minimum_models'=>'5','maximum_models'=>'10','starting_models'=>'5','additional_model_points'=>'40')));
    }
}
