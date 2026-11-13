<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-ls-armoury.php';
final class ArmouryProfilesTest extends TestCase {
    public function test_profile_fields_are_unique_and_edition_neutral(): void {
        $fields = LS_Armoury::profile_fields();
        self::assertArrayHasKey('movement', $fields);
        self::assertArrayHasKey('save', $fields);
        self::assertCount(count(array_unique(array_keys($fields))), $fields);
    }
    public function test_profile_sanitisation_rejects_markup_and_long_values(): void {
        $profile = LS_Armoury::sanitise_profile(array('movement'=>' 7 ', 'save'=>'2+', 'attacks'=>'<script>', 'wounds'=>str_repeat('1', 40), 'unknown'=>'5'));
        self::assertSame(array('movement'=>'7', 'save'=>'2+'), $profile);
    }
    public function test_profile_sanitisation_handles_missing_and_non_scalar_values(): void {
        self::assertSame(array(), LS_Armoury::sanitise_profile(array('movement'=>array('5'), 'save'=>'', 'strength'=>null)));
    }
}
