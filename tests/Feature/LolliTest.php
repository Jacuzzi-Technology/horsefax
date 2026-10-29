<?php

namespace Tests\Feature;

use App\Harvest\LolliHarvester;
use App\Harvest\LolliParser;
use Tests\TestCase;

/** Lolli Bros. horse-sale catalogs: header lines, sale days and the 14-name pedigree tree (all names fictional). */
class LolliTest extends TestCase
{
    /** Words [xMin, yMin, xMax, yMax, text] for one run of text, each word 6 pt wide per letter. */
    private function textRun(float $x, float $y, string $text, float $h = 11): array
    {
        $out = [];
        foreach (explode(' ', $text) as $w) {
            $out[] = [$x, $y, $x + 6 * mb_strlen($w), $y + $h, $w];
            $x += 6 * mb_strlen($w) + 3;
        }

        return $out;
    }

    /** One 2013-2024 style lot: "LOT 7 NAME AQHA", the foaled line, the owner, then the tree with right-aligned great-grandparents. */
    private function lotWords(float $x0, float $y0, string $lot, string $name, ?array $tree = null): array
    {
        $w = array_merge(
            $this->textRun($x0, $y0 - 4, 'LOT', 20), $this->textRun($x0 + 30, $y0 - 4, $lot, 20),
            $this->textRun($x0 + 50, $y0, $name), $this->textRun($x0 + 230, $y0, 'AQHA'),
            $this->textRun($x0 + 50, $y0 + 13, 'FOALED: 4/16/2013'), $this->textRun($x0 + 160, $y0 + 13, 'STALLION'), $this->textRun($x0 + 220, $y0 + 13, 'RED ROAN'),
            $this->textRun($x0 + 50, $y0 + 26, 'OWNER: SAMPLE CONSIGNOR'),
        );
        $tree ??= ['SAMPLE SIRE', 'SAMPLE DAM', 'SAMPLE SS', 'SAMPLE SD', 'SAMPLE DS', 'SAMPLE DD',
            'SAMPLE SSS', 'SAMPLE SSD', 'SAMPLE SDS', 'SAMPLE SDD', 'SAMPLE DSS', 'SAMPLE DSD', 'SAMPLE DDS', 'SAMPLE DDD'];
        [$s, $d, $ss, $sd, $ds, $dd, $sss, $ssd, $sds, $sdd, $dss, $dsd, $dds, $ddd] = $tree;
        // Top to bottom: sss, ss, ssd, s, sds, sd, sdd, (gap) dss, ds, dsd, d, dds, dd, ddd. Generation 3 is right-aligned at x0+270.
        $rows = [[2, $sss], [1, $ss], [2, $ssd], [0, $s], [2, $sds], [1, $sd], [2, $sdd], [2, $dss], [1, $ds], [2, $dsd], [0, $d], [2, $dds], [1, $dd], [2, $ddd]];
        $y = $y0 + 40;
        foreach ($rows as $i => [$gen, $n]) {
            $x = [$x0, $x0 + 50, $x0 + 270 - 9 * mb_strlen($n)][$gen];
            $w = array_merge($w, $this->textRun($x, $y, $n));
            $y += $i === 6 ? 16 : 11;
        }

        return array_merge($w, $this->textRun($x0, $y + 4, 'Nice gentle stallion, sells sound. Ready to show.', 9));
    }

    public function test_reads_two_lots_side_by_side_with_full_trees(): void
    {
        $page = array_merge($this->lotWords(20, 30, '21', 'SAMPLE LEFT HORSE'), $this->lotWords(310, 30, '22', 'SAMPLE RIGHT HORSE'));
        $lots = LolliParser::catalog([$page]);

        $this->assertCount(2, $lots);
        $left = collect($lots)->firstWhere('lot', '21');
        $this->assertSame('SAMPLE LEFT HORSE', $left['name']);
        $this->assertSame(['AQHA', 'Quarter Horse', true], [$left['registry'], $left['breed'], $left['registered']]);
        $this->assertSame(['2013-04-16', 2013, 'stallion', 'Red Roan'], [$left['birth_date'], $left['foal_year'], $left['sex'], $left['color']]);
        $this->assertSame('SAMPLE SIRE', $left['pedigree']['s']);
        $this->assertSame('SAMPLE DAM', $left['pedigree']['d']);
        $this->assertSame('SAMPLE DS', $left['pedigree']['ds']);
        $this->assertSame('SAMPLE SDD', $left['pedigree']['sdd']);
        $this->assertSame('SAMPLE DDD', $left['pedigree']['ddd']);
        $this->assertCount(14, $left['pedigree']);
        // The consignor and the description are never part of the lot.
        $this->assertStringNotContainsString('CONSIGNOR', json_encode($lots));
        $this->assertStringNotContainsString('gentle', json_encode($lots));
    }

    public function test_a_tree_that_repeats_a_name_inside_itself_is_left_out(): void
    {
        $tree = ['SAMPLE SIRE', 'SAMPLE DAM', 'SAMPLE SIRE', 'SAMPLE SD', 'SAMPLE DS', 'SAMPLE DD',
            'SAMPLE SSS', 'SAMPLE SSD', 'SAMPLE SDS', 'SAMPLE SDD', 'SAMPLE DSS', 'SAMPLE DSD', 'SAMPLE DDS', 'SAMPLE DDD'];
        $lots = LolliParser::catalog([$this->lotWords(20, 30, '5', 'SAMPLE HORSE', $tree)]);

        $this->assertSame('SAMPLE HORSE', $lots[0]['name']);
        $this->assertSame([], $lots[0]['pedigree']);
    }

    public function test_header_lines(): void
    {
        $this->assertSame(['birth_date' => null, 'foal_year' => 2014, 'sex' => 'mare', 'color' => 'Red Roan', 'registry' => 'APHA', 'breed' => 'Paint Horse', 'registered' => true],
            LolliParser::describe('FOALED: 5/2014 MARE-OPEN RED ROAN', 'ApHA'));
        $this->assertSame(['birth_date' => '2016-03-31', 'foal_year' => 2016, 'sex' => 'gelding', 'color' => null, 'registry' => null, 'breed' => null, 'registered' => false],
            LolliParser::describe('FOALED: 3-31-2016 GELDING', 'GRADE'));
        $this->assertSame(['birth_date' => '2013-04-28', 'foal_year' => 2013, 'sex' => 'mare', 'color' => 'Buckskin', 'registry' => 'AQHA', 'breed' => 'Quarter Horse', 'registered' => true],
            LolliParser::describeLine('04/28/2013 • MARE BRED • BUCKSKIN • AQHA'));
        $this->assertSame(['birth_date' => '2023-06-01', 'foal_year' => 2023, 'sex' => 'mare', 'color' => 'Sorrel & White', 'registry' => null, 'breed' => null, 'registered' => null],
            LolliParser::describeLine('6/1/2023 - MARE - SORREL & WHITE - GYPSY CROSS'));
        $this->assertSame('colt', LolliParser::describe('FOALED: 4/30/19 STUD COLT BUCKSKIN', 'AQHA')['sex']);
        $this->assertSame(2008, LolliParser::describe('07/030/2008 MARE/BRED RED DUN', 'AQHA')['foal_year']); // a typo'd day keeps the year
    }

    public function test_names_lose_barn_names_and_non_names(): void
    {
        $this->assertSame('SAMPLE SWEET EYED', LolliParser::cleanName('Sample Sweet Eyed (Sweetie)'));
        $this->assertSame('SAMPLE JIGSAW', LolliParser::cleanName('SAMPLE JIGSAW “JIGS”'));
        $this->assertSame('SAMPLE MAYNARD', LolliParser::cleanName('SAMPLE MAYNARD AKA ROMEO'));
        $this->assertSame('SAMPLE MICK', LolliParser::cleanName('(TUFF) SAMPLE MICK'));
        $this->assertSame("SAMPLE'S ELEGANT", LolliParser::cleanName('SAMPLE’S ELEGANT'));
        $this->assertSame('', LolliParser::cleanName('APPL FOR'));
        $this->assertSame('', LolliParser::cleanName('FOALED: 4/2/13'));
        $this->assertSame('', LolliParser::cleanName('OWNER: SAMPLE PERSON'));
        $this->assertSame('', LolliParser::cleanName('15 HAND, 7 YEAR OLD FINISHED RANCH HORSE.'));
    }

    public function test_sale_days_come_from_the_catalogs_own_month(): void
    {
        $this->assertSame(['2015-03-06', '2015-03-07'], LolliHarvester::saleDays(2015, 3, 'Lolli Bros. MARCH 6 & 7, 2015 Horse Sale'));
        $this->assertSame(['2017-05-05', '2017-05-06'], LolliHarvester::saleDays(2017, 5, 'May 5-6, 2017'));
        $this->assertSame(['2024-05-04'], LolliHarvester::saleDays(2024, 5, 'Saturday, May 4th, 2024'));
        // A March catalog advertising the May sale gives no March date.
        $this->assertSame([], LolliHarvester::saleDays(2019, 3, 'Next sale: May 3-4, 2019'));
    }
}
