<?php
use PHPUnit\Framework\TestCase;

final class ArmouryIndexTest extends TestCase {
    public function test_index_filters_by_release_and_name_or_slug(): void {
        $rows = array(
            (object)array('release_id'=>1,'title'=>'Power Fist','slug'=>'power-fist'),
            (object)array('release_id'=>2,'title'=>'Power Fist','slug'=>'power-fist'),
            (object)array('release_id'=>1,'title'=>'Thunder Hammer','slug'=>'forge-hammer'),
        );
        self::assertCount(2, LS_Armoury::index_matches($rows, 1, ''));
        self::assertCount(1, LS_Armoury::index_matches($rows, 1, 'power'));
        self::assertSame('Thunder Hammer', LS_Armoury::index_matches($rows, 1, 'forge')[0]->title);
        self::assertCount(2, LS_Armoury::index_matches($rows, 0, 'power'));
    }

    public function test_index_pagination_clamps_out_of_range_pages(): void {
        $records = range(1, 45);
        $first = LS_Armoury::index_page($records, 0);
        self::assertSame(1, $first['page']);
        self::assertCount(20, $first['rows']);
        $last = LS_Armoury::index_page($records, 999);
        self::assertSame(3, $last['page']);
        self::assertSame(45, end($last['rows']));
        self::assertSame(45, $last['total']);
    }

    public function test_index_empty_results_have_one_safe_page(): void {
        $index = LS_Armoury::index_page(array(), 99);
        self::assertSame(1, $index['page']);
        self::assertSame(1, $index['pages']);
        self::assertSame(array(), $index['rows']);
    }
}
