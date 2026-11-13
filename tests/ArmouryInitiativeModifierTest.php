<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-armoury.php';

final class ArmouryInitiativeModifierTest extends TestCase {
    public function test_signed_initiative_modifiers_are_normalised(): void {
        foreach (array('-2'=>'-2', '+1'=>'+1', '0'=>'0', '2'=>'+2', '-0'=>'0') as $input=>$expected) {
            $data = LS_Armoury::sanitise_equipment(array('type'=>'melee', 'initiative_modifier'=>(string)$input));
            self::assertSame($expected, $data['profile']['initiative_modifier']);
        }
    }

    public function test_invalid_initiative_modifiers_are_ignored(): void {
        foreach (array('', 'fast', '1+2', '--2', '2.5', '100', '-100', '<script>') as $value) {
            $data = LS_Armoury::sanitise_equipment(array('type'=>'melee', 'initiative_modifier'=>$value));
            self::assertArrayNotHasKey('initiative_modifier', $data['profile']);
        }
    }

    public function test_existing_weapon_profile_fields_are_preserved(): void {
        $data = LS_Armoury::sanitise_equipment(array('type'=>'melee', 'strength'=>'+3', 'ap'=>'2', 'damage'=>'2', 'initiative_modifier'=>'-2'));
        self::assertSame('melee', $data['type']);
        self::assertSame('+3', $data['profile']['strength']);
        self::assertSame('2', $data['profile']['ap']);
        self::assertSame('2', $data['profile']['damage']);
        self::assertSame('-2', $data['profile']['initiative_modifier']);
    }
}
