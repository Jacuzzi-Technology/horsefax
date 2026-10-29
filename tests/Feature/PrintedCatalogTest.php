<?php

namespace Tests\Feature;

use App\Harvest\PrintedCatalogParser;
use Tests\TestCase;

/** Richard's other printed sale catalogs: description lines and positional trees (all names fictional). */
class PrintedCatalogTest extends TestCase
{
    public function test_identity_line(): void
    {
        $this->assertSame(['registry' => 'AQHA', 'breed' => 'Quarter Horse', 'registered' => true, 'registration_number' => '5243447', 'sex' => 'gelding', 'birth_date' => null, 'foal_year' => 2009, 'color' => 'Sorrel'],
            PrintedCatalogParser::identity('AQHA', '5243447', false, 'GELDING SORREL 2009'));
        $grade = PrintedCatalogParser::identity(null, null, true, 'GELDING PALOMINO X YR OLD');
        $this->assertSame([false, 'gelding', 'Palomino'], [$grade['registered'], $grade['sex'], $grade['color']]);
        $this->assertSame(['Pony', 'mare', 'Sorrel'], array_values(array_intersect_key(PrintedCatalogParser::identity(null, null, true, 'MARE PONY SORREL'), array_flip(['breed', 'sex', 'color']))));
    }

    public function test_free_description(): void
    {
        $this->assertSame(['Rock Lane Twelve', '4 yr old Registered Black Percheron Stallion.'], PrintedCatalogParser::splitName('Rock Lane Twelve 4 yr old Registered Black Percheron Stallion.'));
        $this->assertSame('Silver Rainbow', PrintedCatalogParser::splitName('Silver Rainbow Born 3-16-25 Registered Gypsy')[0]);
        $this->assertSame('', PrintedCatalogParser::splitName('Weanling Palomino stud colt')[0]);

        $d = PrintedCatalogParser::free('4 yr old Registered Black Percheron Stallion. Stands 17.1 HH', 2024);
        $this->assertSame([true, 2020, 'stallion', 'Black', 'Percheron'], [$d['registered'], $d['foal_year'], $d['sex'], $d['color'], $d['breed']]);
        $d = PrintedCatalogParser::free('Foaled 4/21/23 Registered Sorrel Belgian Stud Colt.', 2024);
        $this->assertSame(['2023-04-21', 'colt', 'Belgian'], [$d['birth_date'], $d['sex'], $d['breed']]);
        $d = PrintedCatalogParser::free('Quarter Horse Gelding Black 5 Year Old', 2026);
        $this->assertSame(['gelding', 2021], [$d['sex'], $d['foal_year']]); // "Quarter Horse" is no stallion
        $this->assertNull(PrintedCatalogParser::free('3 yr old Percheron/Quarter Horse Gelding', 2026)['breed']); // a cross
        $this->assertFalse(PrintedCatalogParser::free('5 yr old Grade Blonde Belgian Gelding', 2024)['registered']);
    }

    public function test_tree_from_left_aligned_columns(): void
    {
        $seg = fn ($x, $y, $t) => ['x' => $x, 'x1' => $x + 6 * strlen($t), 'y' => $y, 'yc' => $y + 5, 'h' => 10, 't' => $t];
        $tree = [
            $seg(80, 100, 'SAMPLE SSS'), $seg(40, 110, 'SAMPLE SS'), $seg(80, 120, 'SAMPLE SSD'), $seg(0, 130, 'SAMPLE SIRE'),
            $seg(80, 140, 'SAMPLE SDS'), $seg(40, 150, 'SAMPLE SD'), $seg(80, 160, 'SAMPLE SDD'),
            $seg(80, 180, 'SAMPLE DSS'), $seg(40, 190, 'SAMPLE DS'), $seg(80, 200, 'SAMPLE DSD'), $seg(0, 210, 'SAMPLE DAM'),
            $seg(80, 220, 'SAMPLE DDS'), $seg(40, 230, 'SAMPLE DD'), $seg(80, 240, 'SAMPLE DDD'),
            $seg(0, 260, 'Very pretty bay mare that will make a great horse.'),
        ];
        $p = PrintedCatalogParser::tree($tree, 90, 'SAMPLE HORSE');
        $this->assertSame(['SAMPLE SIRE', 'SAMPLE DAM', 'SAMPLE SS', 'SAMPLE DD', 'SAMPLE SSS', 'SAMPLE DDD'], [$p['s'], $p['d'], $p['ss'], $p['dd'], $p['sss'], $p['ddd']]);
        $this->assertCount(14, $p);

        // Columns that don't nest (a parent outside its own parents) are not a tree.
        $tree[3]['y'] = 95;
        $this->assertSame([], PrintedCatalogParser::tree($tree, 90, 'SAMPLE HORSE'));
    }
}
