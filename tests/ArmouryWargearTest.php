<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-armoury.php';
final class ArmouryWargearTest extends TestCase {
    public function test_equipment_profile_sanitises_and_bounds_fields(): void {
        $data = LS_Armoury::sanitise_equipment(array('type'=>'ranged','range'=>'24"','strength'=>'5','ap'=>'3','damage'=>'<script>','rules'=>'Breaching, Rending, <script>'));
        self::assertSame('ranged', $data['type']);
        self::assertSame('5', $data['profile']['strength']);
        self::assertArrayNotHasKey('damage', $data['profile']);
        self::assertSame(array('Breaching','Rending'), $data['rules']);
    }
    public function test_invalid_type_defaults_to_equipment(): void {
        self::assertSame('equipment', LS_Armoury::sanitise_equipment(array('type'=>'invalid'))['type']);
    }
    public function test_assignments_are_limited_to_release_and_known_models(): void {
        $links = LS_Armoury::sanitise_equipment_links(array(
            array('slug'=>'flamer','model'=>'Firedrake Master'),
            array('slug'=>'other-release','model'=>''),
            array('slug'=>'flamer','model'=>'Unknown'),
            array('slug'=>'flamer','model'=>'Firedrake Master'),
        ), array('flamer'), array('Firedrake Master'));
        self::assertSame(array(array('slug'=>'flamer','model'=>'Firedrake Master')), $links);
    }
}
