<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-armoury.php';
final class ArmouryChambersTest extends TestCase {
    public function test_default_and_invalid_chambers_fall_back_to_units(): void {
        self::assertSame('units', LS_Armoury::admin_chamber(array()));
        self::assertSame('units', LS_Armoury::admin_chamber(array('ls_chamber'=>'unexpected')));
        self::assertSame('units', LS_Armoury::admin_chamber(array('ls_chamber'=>array('rules'))));
    }
    public function test_every_chamber_is_allowlisted(): void {
        foreach (array('editions','releases','factions','units','wargear','rules') as $chamber) {
            self::assertSame($chamber, LS_Armoury::admin_chamber(array('ls_chamber'=>$chamber)));
        }
    }
    public function test_mutation_types_return_to_their_own_chambers(): void {
        foreach (array('edition'=>'editions','release'=>'releases','faction'=>'factions','unit'=>'units','wargear'=>'wargear') as $kind=>$chamber) {
            self::assertSame($chamber, LS_Armoury::chamber_for_kind($kind));
        }
        self::assertSame('units', LS_Armoury::chamber_for_kind('invalid'));
    }
    public function test_navigation_preserves_catalogue_and_release_preview(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-ls-armoury.php');
        self::assertStringContainsString('nav-tab-active', $source);
        self::assertStringContainsString("'ls_chamber'=>'units'", $source);
        self::assertStringContainsString("'ls_chamber'=>'releases', 'ls_delete_preview'", $source);
    }
}
