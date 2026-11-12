<?php

namespace Tests\Feature;

use App\Actions\Record\BuildRecordsFromResults;
use App\Harvest\WaverlyHarvester;
use App\Harvest\WaverlyParser;
use App\Models\DataSource;
use App\Models\Horse;
use App\Models\HorseIdentifier;
use App\Models\SaleLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Waverly Midwest Horse Sale catalogs, three layouts: facts only, .bid lots of the same horse paired (all names fictional). */
class WaverlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_lot_layout_reads_by_out_of_pedigrees_and_drops_people(): void
    {
        $text = <<<'TXT'
                              SAMPLE CONSIGNOR, IA
LOT 11 - Sample View Buttercup M140348                                Belgian Mare
Foaled: March 18, 2019
Red sorrel, stripe, light mane and tail
Sire:    Sample Gordy S68529, by Sample Tami's
         Maverick, out of Sample Jim Doll
         Sample Tami's Maverick S58740, by Sample Eddie, out of Sample Tasha
         Sample Jim Doll M122059, by Sample Captain Jim, out of Sample Kayla
Dam: Sample Brittany M132802 -1039184-, by Sample
         Manifest, out of Sample Bridget
         Sample Manifest S69919 -36060-, by Sample Ledge 70957 CN, out of Sample
         Proud Mary
         Sample Bridget M34583(CN), by Sample Red Chief, out of Sample Old Bridget
Stands approx 17-2 hands. In foal to Sample Apollo. Phone 319-555-0100.
––––––––––––––––––––––––––––––––––––––––––––––––––
LOT 12 - Charlotte, a grade black 17 year old Percheron
mare with a white star. Stands approx 17 hands.
––––––––––––––––––––––––––––––––––––––––––––––––––
LOT 13 - Ray and Don, a team of 9 and 10 year old Percheron geldings.
LOT 14 - A grade weanling sorrel Belgian stallion colt with strip.
LOT 15 - Sample Pony Doris 23839-M                                    Shire Mare
Foaled: May 25, 2000
Bay, full white blaze
Sire:      Sample Valley John 2287-S,
             by Sample Equalizer 22539-S
Dam:       Sample Pretty Girl B-2281,
             by Sample George 22065-S
2nd Dam: Sample Kate A-22807-M,
             by Sample Grant 22013-S
Stands approx 16.3 hands.
TXT;
        $lots = WaverlyParser::catalogLot($text);
        $this->assertSame(['11', '12', '15'], array_column($lots, 'lot')); // the team and the unnamed weanling are skipped
        [$a, $b, $c] = $lots;
        $this->assertSame(['Sample View Buttercup', 'M140348', 'Belgian', 'mare', 2019, '2019-03-18', 'Red sorrel', true],
            [$a['name'], $a['registration_number'], $a['breed'], $a['sex'], $a['foal_year'], $a['birth_date'], $a['color'], $a['registered']]);
        $this->assertSame(['s' => 'Sample Gordy', 'ss' => "Sample Tami's Maverick", 'sd' => 'Sample Jim Doll', 'd' => 'Sample Brittany', 'ds' => 'Sample Manifest',
            'dd' => 'Sample Bridget', 'sss' => 'Sample Eddie', 'ssd' => 'Sample Tasha', 'sds' => 'Sample Captain Jim', 'sdd' => 'Sample Kayla',
            'dss' => 'Sample Ledge', 'dsd' => 'Sample Proud Mary', 'dds' => 'Sample Red Chief', 'ddd' => 'Sample Old Bridget'], $a['pedigree']);
        $this->assertSame(['Charlotte', false, 'Percheron', 'mare', 17, 'Black'], [$b['name'], $b['registered'], $b['breed'], $b['sex'], $b['age'], $b['color']]);
        // 2013's layout: "by" on its own line, "2nd Dam:" for the female line; dashed numbers come off the names.
        $this->assertSame(['Sample Pony Doris', '23839-M'], [$c['name'], $c['registration_number']]);
        $this->assertSame(['s' => 'Sample Valley John', 'ss' => 'Sample Equalizer', 'd' => 'Sample Pretty Girl', 'ds' => 'Sample George', 'dd' => 'Sample Kate', 'dds' => 'Sample Grant'], $c['pedigree']);
        foreach (['CONSIGNOR', '319-555', 'Apollo', 'Stands'] as $gone) {
            $this->assertStringNotContainsString($gone, json_encode($lots));
        }
    }

    public function test_2026_chart_layout_skips_the_horses_own_name_at_the_left(): void
    {
        $text = <<<'TXT'
Lot 66 - Sample Louise of Nightlight                                Registered Clydesdale
Sample Consignor, Stacyville, IA                                                   Mare
Fitted by: Sample Fitter, McIntire IA                                              Black
                                                                      Sample Pride
                                               Sample Zoom
                                                                      Sample Belinda
                       Sample Nightlight
                                                                      Sample Omega
                                               Sample Chanel
                                                                      Sample Jewel
Sample Louise Of Nightlight Long
 Clydesdale                                                           Sample Billy Boy
                                               Sample Lockinvar
 Reg. #29473                                                          Sample Pepper
                       Sample Greta's Garbo
                                                                      Sample Joe
                                               Sample Greta
                                                                      Sample Harriet
                  Sample Louise of Nightlight, a 3 year old black Clydes-
                  dale mare. Foaled 03/04/2023. Reducing our herd size.
____________________________________________________________________
Lot 67 - Sample Linda                                                       Belgian Filly
Sample Consignor, Hazleton, IA                                                     Blonde
Sample Linda, a yearling blonde Belgian filly. Foaled: 4/12/2025.
____________________________________________________________________
Lot 68 - Sample Dean                                     Grade Percheron Gelding
Sample Consignor, Delta, IA                                               Gray
Sample Dean, a 4 year old gray Percheron gelding.
____________________________________________________________________
Lot 69 -                                                            Grade Belgian Stallion
TXT;
        $lots = WaverlyParser::catalog2026($text);
        $this->assertSame(['66', '67', '68'], array_column($lots, 'lot'));
        [$a, $b, $c] = $lots;
        $this->assertSame(['Sample Louise of Nightlight', '29473', 'Clydesdale', 'mare', 'Black', 2023, '2023-03-04', true],
            [$a['name'], $a['registration_number'], $a['breed'], $a['sex'], $a['color'], $a['foal_year'], $a['birth_date'], $a['registered']]);
        $this->assertSame(['Sample Nightlight', "Sample Greta's Garbo", 'Sample Zoom', 'Sample Chanel', 'Sample Lockinvar', 'Sample Greta', 'Sample Pride', 'Sample Harriet'],
            [$a['pedigree']['s'], $a['pedigree']['d'], $a['pedigree']['ss'], $a['pedigree']['sd'], $a['pedigree']['ds'], $a['pedigree']['dd'], $a['pedigree']['sss'], $a['pedigree']['ddd']]);
        $this->assertSame(['Sample Linda', null, 'Belgian', 'filly', 2025], [$b['name'], $b['registered'], $b['breed'], $b['sex'], $b['foal_year']]);
        $this->assertSame([false, 4, []], [$c['registered'], $c['age'], $c['pedigree']]);
        foreach (['Consignor', 'Fitter', 'herd size'] as $gone) {
            $this->assertStringNotContainsString($gone, json_encode($lots));
        }
    }

    public function test_2004_flyers_keep_registered_horses_only(): void
    {
        $flow = "Partial Listing of Draft Horses - Sample Lane Angie Registered Belgian Mare Foaled: April 20th, 2000. Red sorrel, stripe. Sire: Sample Classic Mark. Dam: Sample Rosie. Stands approx. 17-2 hands. "
            .'- Brad and Buster, a team of grade 6 year old grey Percheron geldings. They stand 18 hands. '
            .'- (team pictured) - Sample Farm Brenda Registered Percheron Mare Foaled: February 14, 2001. Black; small star. Sire: Sample Trademark. Dam: Sample Brandi 36448 Can. Great bottoms. ';
        $lots = WaverlyParser::catalogFlyer($flow);
        $this->assertSame(['Sample Lane Angie', 'Sample Farm Brenda'], array_column($lots, 'name'));
        $this->assertSame([null, 'Belgian', 'mare', 2000, '2000-04-20', 'Red sorrel', ['s' => 'Sample Classic Mark', 'd' => 'Sample Rosie']],
            [$lots[0]['lot'], $lots[0]['breed'], $lots[0]['sex'], $lots[0]['foal_year'], $lots[0]['birth_date'], $lots[0]['color'], $lots[0]['pedigree']]);
        $this->assertSame('Sample Brandi', $lots[1]['pedigree']['d']);
    }

    public function test_sale_from_file_name_cover_or_weekday(): void
    {
        $this->assertSame(['spring-2026', 'Waverly Midwest Horse Sale, Spring 2026', '2026-03-24'],
            array_values(array_intersect_key(WaverlyParser::sale('2026-02_WAV-S26-FINAL.pdf', "Tuesday & Wednesday, March 24 & 25 Listings\n"), array_flip(['key', 'name', 'date']))));
        $this->assertSame('fall-2019', WaverlyParser::sale('root_WMHS_F19.pdf', 'October 1')['key']);
        $this->assertSame(['fall-2004', '2004-10-05'], [WaverlyParser::sale('root_HORSES_1.PDF', "2004 Fall Draft Horse Sale\n October 5, 6, 7, 8, & 9")['key'], WaverlyParser::sale('root_HORSES_1.PDF', "2004 Fall Draft Horse Sale\n October 5, 6, 7, 8, & 9")['date']]);
        // No year on the cover: the year (the Archive's, or before) that puts these dates on these weekdays.
        $cover = "Tuesday & Wednesday, October 6 & 7 - Machinery\nThursday, October 8 - Draft Horses\nFriday, October 9 - Draft Cross\nWednesday, October 7 - Tack\n";
        $this->assertSame(['fall-2015', '2015-10-07'], [WaverlyParser::sale('root_Thursday.pdf', $cover, 2016)['key'], WaverlyParser::sale('root_Thursday.pdf', $cover, 2016)['date']]);
        $this->assertNull(WaverlyParser::sale('root_Thursday.pdf', $cover)['key']);
    }

    public function test_candidate_files_and_complete_pdfs(): void
    {
        $this->assertTrue(WaverlyHarvester::candidate('http://www.waverlysales.com:80/Thursday_F17.pdf'));
        $this->assertFalse(WaverlyHarvester::candidate('https://waverlysales.com/wp-content/uploads/gravity_forms/5-abc/2025/02/Sample-Pedigree.pdf'));
        $this->assertFalse(WaverlyHarvester::candidate('https://waverlysales.com/wp-content/uploads/2026/01/WSC-Consignment-Form.pdf'));
        $this->assertSame('2026-02_WAV-S26-FINAL.pdf', WaverlyHarvester::fileName('https://waverlysales.com/wp-content/uploads/2026/02/WAV-S26-FINAL.pdf'));
        $this->assertSame('root_HORSES_1.PDF', WaverlyHarvester::fileName('http://www.waverlysales.com:80/HORSES~1.PDF'));
        $this->assertSame('https://www.waverlysales.com/Catalog_F20.pdf', WaverlyHarvester::liveUrl('http://www.waverlysales.com:80/Catalog_F20.pdf'));
        $this->assertTrue(WaverlyHarvester::complete("%PDF-1.6\n...\n%%EOF\n"));
        $this->assertFalse(WaverlyHarvester::complete("%PDF-1.6\n...cut off by the Archive at 1 MB"));
    }

    public function test_catalog_lot_pairs_with_the_bid_lot_of_the_same_horse(): void
    {
        $system = BuildRecordsFromResults::system();
        $bid = DataSource::firstOrCreate(['key' => 'as-waverly'], ['name' => 'Waverly Sales Company (Midwest Horse Sale)', 'url' => 'https://www.waverlysales.bid', 'permission' => 'no_terms_posted']);
        $horse = new Horse(['registered_name' => 'Sample Connroyal', 'birth_precision' => 'unknown']);
        $horse->horsefax_id = \App\Actions\Record\CreateHorse::newId();
        $horse->created_by = $system->id;
        $horse->forceFill(['source' => 'results', 'listed' => true, 'public_summary' => true, 'identity_status' => 'unverified'])->save();
        $bidLot = fn ($id, $lot, $name, $values = []) => SaleLot::record($bid->id, $id, $values + ['sale_name' => 'Waverly: Thursday', 'sale_date' => '2026-03-26', 'lot' => $lot, 'horse_name' => $name,
            'status' => 'sold', 'price_cents' => 270000, 'source_url' => 'https://www.waverlysales.bid/x']);
        $onRecord = $bidLot('as:1', '68', 'Sample Connroyal', ['horse_id' => $horse->id, 'match_tier' => 'created']);
        $waiting = $bidLot('as:2', '13', 'Sample Captain Opal', ['review_status' => 'unmatched']);

        $sale = ['key' => 'spring-2026', 'name' => 'Waverly Midwest Horse Sale, Spring 2026', 'season' => 'Spring', 'year' => 2026, 'date' => '2026-03-24'];
        $lot = fn ($no, $name, $reg, $ped) => ['lot' => $no, 'name' => $name, 'registered' => true, 'registration_number' => $reg, 'registry' => null, 'breed' => 'Belgian',
            'sex' => 'gelding', 'foal_year' => 2019, 'birth_date' => null, 'age' => null, 'color' => 'Sorrel', 'pedigree' => $ped];
        $catalogs = [['file' => 'x.pdf', 'info' => ['url' => 'https://waverlysales.com/x.pdf'], 'sale' => $sale, 'lots' => [
            $lot('68', "Riverglen's Sample Connroyal", 'S99901', ['s' => 'Sample Uconn', 'd' => 'Sample Qutie']),
            $lot('13', 'Sample Captain Opal', 'M99902', ['s' => 'Sample Opie', 'd' => 'Sample Opaline']),
        ]]];
        $out = (new WaverlyHarvester(0, null, sys_get_temp_dir().'/wav-'.uniqid()))->load($catalogs);
        $this->assertSame(2, $out['same_horse_as_bid_lot']);

        // 1. The .bid lot is on a record: the catalog lot joins it, adds its number and pedigree, and adds no second entry.
        $c68 = SaleLot::where('external_id', 'wav:spring-2026:68')->first();
        $this->assertSame([$horse->id, WaverlyHarvester::SAME_SALE, 'offered', null, '2026-03-26'], [$c68->horse_id, $c68->review_status, $c68->status, $c68->price_cents, $c68->sale_date->toDateString()]);
        $this->assertTrue(HorseIdentifier::where('horse_id', $horse->id)->where('value_normalized', 'S99901')->exists());
        $this->assertSame('Sample Uconn', $horse->pedigree()->where('position', 's')->value('name'));
        // 2. The catalog makes the record (registered name, number); the waiting .bid lot of the same sale and lot goes onto it.
        $c13 = SaleLot::where('external_id', 'wav:spring-2026:13')->first();
        $this->assertNotNull($c13->horse_id);
        $this->assertSame([$c13->horse_id, 'same_lot'], [$waiting->fresh()->horse_id, $waiting->fresh()->match_tier]);
        $this->assertSame(WaverlyHarvester::SAME_SALE, $c13->review_status);
        // A rerun changes nothing.
        $again = (new WaverlyHarvester(0, null, sys_get_temp_dir().'/wav-'.uniqid()))->load($catalogs);
        $this->assertSame([2, 0], [$again['already'], $again['same_horse_as_bid_lot']]);
        $this->assertSame($onRecord->horse_id, $onRecord->fresh()->horse_id);
    }
}
